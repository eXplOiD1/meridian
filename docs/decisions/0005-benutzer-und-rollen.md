# 0005 – Benutzer- und Rollenverwaltung (Phase 4a)

Status: **Entwurf** (architekt, 09.10.2026). Alex war nicht erreichbar: offene Punkte stehen mit Empfehlung in §9
(B1–B14). Bis Alex entscheidet, gilt jeweils die Empfehlung; ändert Alex eine Entscheidung, wird nur der
betroffene Schritt in `TODO.md` (Phase 4a) angepasst. Gilt für die Schritte S1–S8, U1–U5 und R von Phase 4a.

Dieser Entwurf ändert keinen Produktcode. Interfaces und Migration stehen hier als Skizze. Die Datei unter
`migrations/` legt erst S1 an (der `Migrator` spielt alles dort sofort ein). Regeländerungen für `CLAUDE.md`,
Skills und Hook: §10.

---

## 1. Ausgangslage

| Vorhanden | Folgerung |
|---|---|
| `users` (`username` UNIQUE NOCASE, `display_name`, `password_hash`, `totp_*`, `is_active`, `last_login_at`), `roles` (3 Standardrollen aus 0001), `role_permissions`, `user_roles` (UNIQUE `user_id, role_id`, `all_categories` aus 0004), `user_role_categories` (CASCADE auf Kategorie) | Mehrere Zuweisungen je Benutzer sind schon möglich. Neu: Soft-Delete, Pflicht-Passwortwechsel, Ablauf des Einmalpassworts, Sitzungsangaben, Recht `categories.manage`, Trigger für „alle ⇒ keine Zeilen“. |
| `UserRepository::grantsFor()` lädt nur aktive Benutzer, „alle“ nur bei Flag **und** ohne Zeilen | Bleibt die einzige Quelle für Rechte. Pflicht-Passwortwechsel und Löschen wirken hier fail-closed (§3.3). |
| `RoleGrant::allows()` verweigert gefährliche Rechte jeder beschränkten Rolle; `AccessControl::scope()` | Unverändert. Neue Prüfungen gegen Rechteausweitung bauen auf `can()` auf, nicht daneben. |
| CLI `user:create` (immer `all_categories = 1`), `user:password` (`PasswordReset`: Sitzungen beenden, Sperre aufheben, Audit), `category:create`, `auth:unlock` | Web-API benutzt dieselben Bausteine. CLI bleibt Notausgang (Vertrauensgrenze OS-Benutzer). |
| `AuthService::enableTwoFactor/disableTwoFactor` prüfen das Passwort mit `reserveOrFail()`/`fail()` gegen die Sperre | Daraus wird die Passwort-Bestätigung für gefährliche Verwaltungsaktionen (`confirmPassword`, §4.4). |
| `SessionManager` speichert nur Hash, Zeiten; `endAllForUser()` | Für die Sitzungsübersicht fehlen Kennung für „diese Sitzung“, Browser, Adresse (B8). |
| FK auf `users`: `audit_log.user_id`, `runs.started_by`, `jobs.owner_id`, `settings.updated_by`, `http_internal_targets.created_by` mit `SET NULL`; `sessions`, `api_tokens`, `recovery_codes`, `user_roles` mit `CASCADE` | Hartes Löschen würde die Urheberschaft im Audit-Log und im Verlauf löschen → Soft-Delete (B2). |
| FK auf `categories`: `jobs.category_id SET NULL`, `user_role_categories CASCADE`, `http_internal_targets CASCADE` | `SET NULL` bei Jobs verengt (Job ohne Kategorie sieht nur „alle“), überrascht aber. Löschen einer Kategorie mit Jobs wird abgelehnt (B6). |
| `AdminController`: `GET /api/audit`, `POST /api/users/unlock` (prüft Recht **vor** CSRF) | Neue Endpunkte prüfen in der Reihenfolge aus 0003 §4.1 (CSRF vor dem Laden). Reihenfolge in `unlock` in S3 angleichen (kein Leck, nur Einheitlichkeit). |
| Oberfläche: Menü „Benutzer & Rollen“ mit Hinweis „bald“, `lib/permissions.ts`, `Confirm`, `FormField`, Karten-/Tabellenstil aus Einstellungen und Audit-Log | Neue Screens im selben Stil (§7). |

---

## 2. Entscheidungen

### E1 – Drei feste Rollen, mehrere Zuweisungen je Benutzer, keine eigenen Rollen im MVP (B1)

- Rollen bleiben **Admin, Operator, Beobachter** mit den Rechten aus den Migrationen. Rollen und ihre Rechte sind im
  MVP **nicht** über API oder Oberfläche änderbar; die Oberfläche zeigt sie als Rechte-Matrix nur lesend.
- Ein Benutzer hat eine **Liste von Zuweisungen** (Rolle + Geltungsbereich), z. B. „Operator für NAS und Web“ plus
  „Beobachter für alle Kategorien“. Je Rolle höchstens eine Zuweisung (vorhandenes `UNIQUE (user_id, role_id)`).
- Geltungsbereich: **ausdrücklich** `all_categories = true` **oder** eine nicht leere Liste von Kategorie-IDs. Beides
  zugleich oder keins → 422. „Alle“ entsteht nie aus einer leeren Liste.
- Eine Rolle mit mindestens einem gefährlichen Recht (Admin) nur mit `all_categories = true` (422 sonst). Eine
  beschränkte Admin-Zuweisung würde wegen `RoleGrant::allows()` ohnehin nichts Gefährliches freigeben und nur
  verwirren.
- Ein Benutzer ohne Zuweisung ist erlaubt (kann sich anmelden, sieht nur „Mein Konto“); die Liste markiert ihn.

Abwägung eigene Rollen (verworfen für den MVP):

| | Feste Rollen (gewählt) | Eigene Rollen |
|---|---|---|
| Nutzen | reicht für Admin/Operator/Beobachter je Kategorie, den Kern von Cronicle | feinere Rechte (z. B. „nur ausführen“ ohne Bearbeiten) |
| Risiko | gering: Rechte je Rolle stehen in Migrationen, Tests decken drei Fälle | Rollen-Editor ändert Rechte **aller** Zugewiesenen auf einmal; Ausweitungsprüfung und „letzter Admin“ müssen auch bei Rollenänderung greifen; gefährliche Rechte in eigenen Rollen |
| Aufwand | klein | Editor, API, Matrix-Tests je Kombination: +2–3 PT |

Das Datenmodell lässt eigene Rollen später zu (§3.1 bereitet `roles.is_builtin` vor). Alle Prüfungen in diesem
Entwurf sind schon allgemein formuliert (Rechte statt Rollennamen), damit sie dann weiter gelten.

### E2 – Rechte-Katalog

| Recht | Bezeichnung (UI) | Kategoriebezug | gefährlich | Admin | Operator | Beobachter |
|---|---|---|---|---|---|---|
| `jobs.view` | Jobs ansehen | ja | nein | ✓ | ✓ | ✓ |
| `jobs.run` | Jobs ausführen | ja | nein | ✓ | ✓ | – |
| `jobs.edit_http` | HTTP-Jobs bearbeiten | ja | nein | ✓ | ✓ | – |
| `jobs.edit_shell` | Shell-Jobs bearbeiten (Phase 4) | – | **ja** | ✓ | – | – |
| `status_pages.edit` | Statusseiten bearbeiten | ja | nein | ✓ | ✓ | – |
| `users.manage` | Benutzer verwalten, Audit-Log, Sperren aufheben | – | **ja** | ✓ | – | – |
| `categories.manage` | Kategorien verwalten (**neu**, B7) | – | **ja** | ✓ | – | – |
| `network.internal_targets` | Interne Ziele freigeben | – | **ja** | ✓ | – | – |
| `settings.manage` | Einstellungen ändern | – | **ja** | ✓ | – | – |

- `categories.manage` ist gefährlich, weil Löschen und Umbenennen Sichtbarkeit und Freigaben **aller** betroffenen
  Benutzer ändert. Per Migration nur an Admin (Regel „neue Rechte nur an Admin“), im `match` von
  `Permission::isDangerous()` eintragen.
- Künftige Shell-Rechte (Phase 4, z. B. eigene Container-Allowlist pflegen) sind ebenfalls gefährlich und kommen
  über denselben Weg dazu; die Oberfläche liest den Katalog vom Server (`GET /api/roles`), nicht aus einer Liste im
  Frontend.

### E3 – „Letzter Admin“ als Invariante über Rechte, geprüft nach dem Schreiben

**Admin im Sinn der Invariante** = aktiver, nicht gelöschter Benutzer, für den **jedes** `Permission::cases()`
ohne Kategorie erlaubt ist (`AccessControl::can($grants, $p, null)` für alle `$p`). Das ist bei festen Rollen genau
„Admin, alle Kategorien“ und bleibt bei späteren eigenen Rollen richtig.

- Prüfung **nach** jeder Änderung, die Rechte, Aktivität oder Löschung eines Benutzers betrifft, **in derselben**
  `Connection::immediate()`-Transaktion: `AdminInvariant::assertHeld()` zählt die Admins ≥ 1, sonst
  `LastAdminRemoved` → Rollback → 409 „Mindestens ein aktiver Administrator mit allen Rechten muss bleiben. Zuerst
  einen anderen Benutzer zum Administrator machen.“
- Nach dem Schreiben statt vorher, weil so jeder Weg erfasst ist (Rollen ersetzen, deaktivieren, löschen, später
  Rollen-Editor) und zwei gleichzeitige Anfragen („A stuft B herab“, „B stuft A herab“) sich wegen `immediate()`
  serialisieren: die zweite sieht das Ergebnis der ersten und scheitert.
- Betroffene Aktionen: Zuweisungen ersetzen, deaktivieren, löschen. Passwort- und 2FA-Reset ändern keine Rechte.
- Notausgang, falls doch alle Admins unbenutzbar sind (z. B. Passwort und 2FA vergessen): CLI `user:create --role=Admin`
  bzw. `user:password` (OS-Benutzer).

### E4 – Keine Selbst-Aussperrung, keine Rechteausweitung

Handelnder = angemeldeter Benutzer mit `users.manage` (uneingeschränkt, da gefährlich).

1. **Selbst über die Verwaltungs-API:** Eigene Zuweisungen ändern, sich deaktivieren, löschen, das eigene Passwort
   oder 2FA über die Admin-Aktion zurücksetzen → 409 mit Hinweis („Für das eigene Konto: Mein Konto. Rollen ändert
   ein anderer Administrator.“). Anzeigename ändern ist erlaubt.
2. **Vergeben nur, was man hat** (`GrantPolicy::mayAssign`): Für jede neue oder geänderte Zuweisung und jedes Recht
   `p` der Rolle muss gelten: bei `all_categories` `can(actor, p, null)`, sonst `can(actor, p, k)` für **jede**
   Kategorie `k` der Zuweisung. Sonst 403. Bei festen Rollen besitzt nur Admin `users.manage` – die Regel greift
   trotzdem und wird mit einem künstlichen Handelnden getestet (eigene `RoleGrant` im Test).
3. **Niemanden verwalten, der mehr hat** (`GrantPolicy::mayManage`): Jede Aktion an einem Zielbenutzer (Zuweisung,
   Deaktivieren, Löschen, Passwort-/2FA-Reset, Sitzungen beenden) verlangt, dass jedes Recht des Ziels (je Kategorie
   bzw. uneingeschränkt) auch beim Handelnden gilt. Sonst 403.
4. Rechte werden bei jeder Anfrage frisch geladen (`grantsFor()`), deshalb wirkt eine Herabstufung sofort; Sitzungen
   des Ziels müssen dafür nicht enden. Ausnahme Phase 4: eine offene Live-Log-Verbindung (SSE) prüft die Rechte
   periodisch neu (Hinweis an 0004).

### E5 – Soft-Delete statt Löschen (B2)

`DELETE /api/users/{id}` löscht **nicht** die Zeile, sondern in einer Transaktion:

- `deleted_at = now`, `is_active = 0`, `password_hash` = Hash eines zufälligen, verworfenen Werts (eine spätere
  Reaktivierung per Datenbank belebt kein altes Passwort), `password_must_change = 1`;
- `totp_secret_enc = NULL`, `totp_enabled = 0`, `totp_last_step = NULL`; `recovery_codes`, `sessions`,
  `api_tokens` und `user_roles` des Benutzers löschen;
- Benutzername bleibt belegt (`UNIQUE`): niemand kann später unter demselben Namen im Audit-Log als die gelöschte
  Person erscheinen. Anzeigename bleibt für die Lesbarkeit von Audit-Log und Verlauf (B14);
- Audit `user.deleted`; danach `AdminInvariant::assertHeld()`.

Folgen: `audit_log.user_id`, `runs.started_by`, `jobs.owner_id`, `created_by`/`updated_by` behalten ihren Bezug;
die Oberfläche zeigt „(gelöscht)“. Wartende manuelle Läufe des Benutzers enden beim Übernehmen als `skipped` (H1,
`DbRunAuthorizer`, `grantsFor()` liefert für inaktive nichts). Geplante Läufe seiner Jobs laufen weiter (der Besitzer
ist nur Angabe, kein Recht). Gelöschte Benutzer lassen sich nicht reaktivieren (409); stattdessen neuen Benutzer
anlegen. Im Anwendungscode gibt es **kein** `DELETE FROM users` (Hook-Muster, §10).

**Deaktivieren** (`is_active = 0`) ist die umkehrbare Variante: Sitzungen enden sofort, Tokens bleiben gespeichert,
sind aber wirkungslos; Zuweisungen bleiben und gelten nach dem Aktivieren wieder.

### E6 – Einmalpasswort mit Pflichtwechsel statt Admin-Passwort oder Einladungslink (B3, B4)

Anlegen und Admin-Passwort-Reset erzeugen **serverseitig** ein Einmalpasswort (`OneTimePassword::generate()`:
`random_bytes`, 20 Zeichen aus einem eindeutigen Alphabet ohne `0/O/1/l/I`, ≥ 100 Bit, in Vierergruppen angezeigt).

- Es steht **genau einmal** in der Antwort (`initial_password`), sonst nirgends: nicht im Audit, nicht im Log, nicht
  in der DB (nur Argon2id-Hash), nicht in `/me`. Wert nur in `Security\Sealed`, `#[\SensitiveParameter]` überall.
- `users.password_must_change = 1`, `users.password_expires_at = now + 7 Tage` (B4). Nach Ablauf scheitert die
  Anmeldung mit derselben Meldung wie ein falsches Passwort (keine Auskunft über den Zustand).
- **Pflichtwechsel fail-closed:** Solange `password_must_change = 1`, liefert `grantsFor()` eine **leere** Liste;
  `/api/auth/me` meldet `password_change_required: true`; erlaubt sind nur `GET /api/auth/me`,
  `POST /api/auth/password`, `POST /api/auth/logout`. 2FA einrichten und alle eigenen Konto-Aktionen außer dem
  Passwortwechsel → 403 „Bitte zuerst ein eigenes Passwort setzen.“
- Der Admin sieht das Einmalpasswort, kennt aber nie das endgültige Passwort.

Verworfen: (a) **Admin tippt das Passwort** – der Admin kennt es dauerhaft, schwache Passwörter, Übertragung per
Chat. (b) **Einladungslink** – braucht E-Mail (erst Phase 6) oder eine Übergabe des Links, der Token stünde in einer
URL (Browserverlauf, Proxy-Logs; widerspricht „Tokens nie in URLs“), plus eigene Tabelle und Ablauf. Später als
Ergänzung möglich (Token im URL-Fragment, Hash in der DB), nicht im MVP.

### E7 – Gefährliche Verwaltungsaktionen verlangen das eigene Passwort (B5)

Admin-Zuweisung vergeben oder entziehen, Passwort-Reset, 2FA-Reset, Löschen: Anfragekörper enthält
`current_password` des **Handelnden**. Prüfung über `AuthService::confirmPassword()` mit derselben Sperre wie die
Anmeldung (`reserveOrFail()` → `fail()` mit Audit `auth.reauth_failed`; gesperrt → 429 mit `Retry-After`). Eine
gestohlene Sitzung allein reicht so nicht, um sich ein zweites Admin-Konto zu bauen oder 2FA eines anderen
abzuschalten. Falsches Passwort → 403 „Passwort falsch.“, nicht 422 (kein Feldfehler mit Hinweis auf den Wert).

Verworfen: „Sudo-Fenster“ (10 Minuten nach Bestätigung) – braucht Zustand in `sessions`, wenig Gewinn bei seltenen
Aktionen.

### E8 – 2FA-Reset durch Admin

`POST /api/users/{id}/2fa-reset`: Secret, Schritt und Wiederherstellungscodes löschen (`TwoFactor::disable()`),
**alle Sitzungen des Ziels beenden**, Sperre des Benutzernamens aufheben, Audit `user.2fa_reset` (`user:<id> <name>`),
Passwort bleibt. Nur fremde Konten (E4.1), mit `current_password` (E7). Das Ziel richtet 2FA danach selbst neu ein.
Erledigt den offenen Punkt aus Phase 1.

### E9 – Kategorien: anlegen, umbenennen, löschen nur ohne Jobs (B6)

- **Anlegen:** wie `category:create` (`CategoryName::isValid()`, doppelt ohne Rücksicht auf Schreibung → 422). Die neue
  Kategorie gehört niemandem; nur „alle Kategorien“-Zuweisungen sehen sie.
- **Umbenennen:** gleiche Prüfung. Zuweisungen, Freigaben und Jobs hängen an der ID und bleiben; `RoleGrant`
  bekommt den neuen Namen beim nächsten `grantsFor()`. Audit `category.renamed` (`category:<id> Alt → Neu`).
- **Löschen:** nur, wenn **kein Job** (auch kein deaktivierter) die Kategorie hat → sonst 409 mit Anzahl („Kategorie
  enthält 3 Jobs. Jobs zuerst verschieben oder löschen.“). In einer `immediate()`-Transaktion: Jobs zählen →
  löschen (Kaskade entfernt `user_role_categories`-Zeilen und `http_internal_targets` dieser Kategorie, nie werden
  sie global) → Audit `category.deleted` mit `category:<id> <Name> (Zuweisungen: n, Freigaben: m)`.
  `jobs.category_id … SET NULL` bleibt als letzte Sicherung (verengt).
- Zuweisungen, die dadurch keine Kategorie mehr haben, bleiben bestehen, gewähren nichts (fail-closed) und werden in
  der Benutzerliste als „wirkungslos“ markiert (B12). Sie werden nie zu „alle“.
- Vorschau der Folgen: `GET /api/categories/manage` liefert je Kategorie `jobs`, `assignments`, `internal_targets`.

Verworfen: Jobs mitlöschen (zerstörerisch, Verlauf weg) und Jobs auf „ohne Kategorie“ setzen (still, beschränkte
Operatoren verlieren ihre Jobs ohne Hinweis).

### E10 – Eigene Daten und Sitzungen (B8)

- Anzeigename ändern (1–64 Zeichen, keine Steuerzeichen, kein führendes/abschließendes Leerzeichen; ablehnen, nicht
  trimmen). Benutzername bleibt unveränderlich (B11): er ist Anmeldename, Sperr-Bezug und Audit-Ziel.
- Passwort ändern: `current_password` + `new_password` (Regeln von `PasswordHasher`, ≤ 1024 Byte, ≠ aktuelles).
  Prüfung über die Sperre wie bei der Anmeldung. Danach: alle **anderen** Sitzungen beenden, die aktuelle durch eine
  neue ersetzen (neues Cookie, neues CSRF-Token), `password_must_change = 0`, `password_expires_at = NULL`,
  `password_changed_at = now`, Audit `user.password_changed`.
- Sitzungsübersicht: eigene Sitzungen mit `id`, `created_at`, `last_seen_at`, `expires_at`, `current`,
  `user_agent` (gekürzt, maskiert) und `client_ip`. Einzelne beenden (`DELETE …/{id}`, nur eigene → sonst 404),
  „alle anderen beenden“. `token_hash` erscheint nie. Admins sehen bei fremden Benutzern nur die Anzahl und
  können alle beenden (keine IPs fremder Personen in der Verwaltung).

---

## 3. Datenmodell

### 3.1 Migration `0009_users_roles.sql` (Entwurf, anzulegen in S1)

Nummer: die nächste freie beim Anlegen (0008 ist durch `0008_display_host.sql` belegt, das parallel entsteht).
Phase 4a wird vor Phase 4 umgesetzt; legt Phase 4 vorher eine Migration an, rückt diese eine Nummer weiter.

```sql
-- Phase 4a: Benutzer- und Rollenverwaltung (docs/decisions/0005-benutzer-und-rollen.md, §3.1).

-- Benutzer: Soft-Delete, Pflicht-Passwortwechsel, Ablauf des Einmalpassworts.
ALTER TABLE users ADD COLUMN deleted_at TEXT;
ALTER TABLE users ADD COLUMN password_must_change INTEGER NOT NULL DEFAULT 0 CHECK (password_must_change IN (0, 1));
ALTER TABLE users ADD COLUMN password_expires_at TEXT;
ALTER TABLE users ADD COLUMN password_changed_at TEXT;

-- Gelöscht heißt immer inaktiv.
CREATE TRIGGER users_deleted_stays_inactive BEFORE UPDATE OF is_active, deleted_at ON users
    WHEN NEW.deleted_at IS NOT NULL AND NEW.is_active <> 0
BEGIN
    SELECT RAISE(ABORT, 'Ein gelöschter Benutzer bleibt inaktiv.');
END;

-- „Alle Kategorien“ und Einzelkategorien schließen sich aus (grantsFor() liest beides als beschränkt; die
-- Datenbank lässt den Widerspruch gar nicht erst entstehen).
CREATE TRIGGER user_role_categories_not_with_all BEFORE INSERT ON user_role_categories
    WHEN (SELECT all_categories FROM user_roles WHERE id = NEW.user_role_id) = 1
BEGIN
    SELECT RAISE(ABORT, 'Zuweisung gilt für alle Kategorien: keine Einzelkategorien.');
END;
CREATE TRIGGER user_roles_all_without_rows BEFORE UPDATE OF all_categories ON user_roles
    WHEN NEW.all_categories = 1 AND EXISTS (SELECT 1 FROM user_role_categories WHERE user_role_id = NEW.id)
BEGIN
    SELECT RAISE(ABORT, 'Erst die Einzelkategorien entfernen, dann „alle Kategorien“ setzen.');
END;

-- Rollen: Standardrollen kennzeichnen (Vorbereitung für eigene Rollen, E1). Nur Fehlendes ergänzen.
ALTER TABLE roles ADD COLUMN is_builtin INTEGER NOT NULL DEFAULT 0 CHECK (is_builtin IN (0, 1));
UPDATE roles SET is_builtin = 1 WHERE name IN ('Admin', 'Operator', 'Beobachter');

-- Neues gefährliches Recht nur für die Rolle Admin.
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, 'categories.manage' FROM roles r
     WHERE r.name = 'Admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'categories.manage');

-- Kategorienamen eindeutig ohne Rücksicht auf Schreibung (bisher nur im Code geprüft).
-- Bricht ab, wenn es schon Namen gibt, die sich nur in der Schreibung unterscheiden: dann vorher umbenennen
-- (Hinweis in WEITERMACHEN.md beim Deploy).
CREATE UNIQUE INDEX categories_name_nocase ON categories (name COLLATE NOCASE);

-- Sitzungsübersicht (B8). Nur für den Inhaber sichtbar, beim Anlegen gekürzt und ohne Steuerzeichen geschrieben.
ALTER TABLE sessions ADD COLUMN user_agent TEXT CHECK (user_agent IS NULL OR length(user_agent) <= 120);
ALTER TABLE sessions ADD COLUMN client_ip TEXT CHECK (client_ip IS NULL OR length(client_ip) <= 45);

-- Folgen-Vorschau und Grenzen je Handelndem.
CREATE INDEX user_role_categories_category ON user_role_categories (category_id);
CREATE INDEX user_roles_role ON user_roles (role_id);
CREATE INDEX audit_log_user_action ON audit_log (user_id, action, created_at);
```

Kein neues `_enc`-Feld: Einmalpasswörter werden nur als Argon2id-Hash in `password_hash` gespeichert.

### 3.2 Validierung (ablehnen, nie zurechtschneiden; Meldungen ohne Eingabewert)

| Feld | Regel |
|---|---|
| `username` | `^[a-z0-9._-]{2,64}$` (i), wie `UserRepository::create()`; doppelt (NOCASE, auch gelöscht) → 422 „Benutzername ist vergeben.“ |
| `display_name` | String 1–64 Zeichen (mb), keine Steuerzeichen (`\p{Cc}`, `\p{Cf}`), nicht mit Leerzeichen beginnend/endend |
| `assignments` | Liste, 0–3 Einträge (je Rolle höchstens einer), jeder genau `{role_id: int, all_categories: bool, category_ids: list<int>}`; unbekannte Felder → 422 |
| `role_id` | existiert in `roles`; doppelt in der Liste → 422 |
| `all_categories`/`category_ids` | `true` + `[]` **oder** `false` + 1–100 eindeutige, existierende IDs; sonst 422 „Kategorien wählen oder ‚Alle Kategorien‘ ankreuzen.“ Rolle mit gefährlichem Recht und `false` → 422 „Diese Rolle gilt nur für alle Kategorien.“ |
| `current_password`, `new_password` | String ≤ 1024 Byte; neues nach `PasswordHasher` (≥ 8 Zeichen) |
| Kategorie `name` | `CategoryName::isValid()`; doppelt (NOCASE) → 422 |
| Pfad-`{id}` | `[1-9][0-9]{0,17}` (Kernel-Anforderung) |

JSON streng typisiert über `JsonBody::object()` (Tiefe 4): `"2"` ist keine Rollen-ID, `1` ist kein `true`.

### 3.3 Wo die Zustände wirken

| Zustand | `grantsFor()` | `SessionManager::resolve()` | Anmeldung |
|---|---|---|---|
| `is_active = 0` | leer (vorhanden) | keine Sitzung (vorhanden) | „Benutzername oder Passwort falsch.“ (vorhanden) |
| `deleted_at` gesetzt | leer (über `is_active = 0`, Trigger) | keine | wie inaktiv |
| `password_must_change = 1` | **leer** (neu, fail-closed) | Sitzung gültig | erlaubt, `password_change_required` |
| `password_expires_at < now` und `must_change = 1` | – | – | wie falsches Passwort (zählt als Fehlversuch) |

---

## 4. Schnittstellen (Skizze)

### 4.1 Wertobjekte und Dienste

```php
namespace Meridian\User;

/** Eine Zuweisung, wie sie gespeichert werden soll. Entsteht nur über AssignmentValidator. */
final readonly class Assignment
{
    /** @param list<int> $categoryIds leer genau dann, wenn $allCategories */
    public function __construct(public int $roleId, public bool $allCategories, public array $categoryIds) {}
}

final class AssignmentValidator
{
    /**
     * @param mixed $input Rohwert aus dem Anfragekörper
     * @return list<Assignment>
     * @throws \Meridian\Http\ValidationFailed
     */
    public function validate(mixed $input): array;
}

/** Lesemodell für Liste und Detail; nie password_hash, totp_secret_enc, token_hash. */
final readonly class UserSummary { /* id, username, displayName, isActive, deletedAt, totpEnabled,
    passwordChangeRequired, lastLoginAt, createdAt, sessionCount, locked, list<AssignmentView> */ }

final class UserAdminService
{
    /** Ablauf je Methode: §4.3. Alle Schreibvorgänge + Invariante + Audit in Connection::immediate(). */
    public function create(Actor $actor, string $username, string $displayName, array $assignments): CreatedUser;
    public function rename(Actor $actor, int $userId, string $displayName): void;
    /** @param list<Assignment> $assignments ersetzt die ganze Liste */
    public function replaceAssignments(Actor $actor, int $userId, array $assignments, #[\SensitiveParameter] ?string $currentPassword): void;
    public function setActive(Actor $actor, int $userId, bool $active): void;
    public function delete(Actor $actor, int $userId, #[\SensitiveParameter] string $currentPassword): void;
    public function resetPassword(Actor $actor, int $userId, #[\SensitiveParameter] string $currentPassword): OneTimePassword;
    public function resetTwoFactor(Actor $actor, int $userId, #[\SensitiveParameter] string $currentPassword): void;
    public function endSessions(Actor $actor, int $userId): int;
}

/** Handelnder: Benutzer + frisch geladene Rechte + Client-IP (für die Sperre bei der Passwort-Bestätigung). */
final readonly class Actor
{
    /** @param list<\Meridian\Security\RoleGrant> $grants */
    public function __construct(public UserAccount $user, public array $grants, public string $ip) {}
}

/** Prüft nach dem Schreiben, in derselben Transaktion. */
final class AdminInvariant
{
    /** @throws LastAdminRemoved */
    public function assertHeld(): void;
}
```

```php
namespace Meridian\Security;

final class GrantPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    /** Darf $actor diese Zuweisung vergeben? Jedes Recht der Rolle in jedem Geltungsbereich der Zuweisung. */
    public function mayAssign(array $actorGrants, array $rolePermissions, ?array $categoryNames): bool;

    /** Darf $actor einen Benutzer mit $targetGrants verwalten? Ziel hat nichts, was $actor fehlt. */
    public function mayManage(array $actorGrants, array $targetGrants): bool;
}

/** Einmalpasswort. Wert nur in Sealed; __debugInfo/jsonSerialize ohne Wert, (de)serialisieren wirft. */
final class OneTimePassword
{
    public static function generate(): self;
    /** Einzige Stelle, an der der Klartext gelesen wird: Antwort an den Admin und PasswordHasher::hash(). */
    public function reveal(): string;
}
```

```php
namespace Meridian\Auth;

// AuthService, neu:
/** Passwort des Handelnden bestätigen, mit Sperre wie bei der Anmeldung. @throws TooManyAttempts */
public function confirmPassword(UserAccount $user, #[\SensitiveParameter] string $password, string $ip): bool;
/** Eigenes Passwort ändern; liefert die neue Sitzung (alte wird ersetzt). @throws TooManyAttempts */
public function changeOwnPassword(UserAccount $user, Session $current, #[\SensitiveParameter] string $old, #[\SensitiveParameter] string $new, string $ip): ?Session;

// SessionManager, neu:
public function start(int $userId, ?string $userAgent = null, ?string $clientIp = null): Session;  // kürzt/säubert
/** @return list<SessionView> ohne token_hash */
public function listForUser(int $userId, Session $current): array;
public function endById(int $userId, int $sessionId): bool;  // WHERE id = :id AND user_id = :u
public function endOthers(int $userId, Session $keep): int;
public function countForUser(int $userId): int;
```

```php
namespace Meridian\Job;

final class CategoryService
{
    /** Ablauf: §4.3; Audit category.created/renamed/deleted in derselben immediate()-Transaktion. */
    public function create(Actor $actor, string $name): int;
    public function rename(Actor $actor, int $id, string $name): void;
    /** @throws CategoryInUse (409) */
    public function delete(Actor $actor, int $id): void;
    /** @return list<array{id:int,name:string,jobs:int,assignments:int,internal_targets:int}> */
    public function usage(): array;
}
```

Controller: `Http\UserController` (Verwaltung), `Http\AccountController` (eigene Daten, Sitzungen),
`Http\CategoryController`; JSON nur über einen `Http\UserPresenter` mit Feld-Allowlist und maskierten Texten
(Anzeigename, Benutzer-Agent).

### 4.2 Endpunkte und Rechte

Spalten: Admin; **Admin/A** = Zuweisung Admin nur für Kategorie A (nach §3.2 nicht mehr anlegbar, aber in Tests per
Datenbank erzeugt); Op; Op/A; Beob; anonym. Alle ändernden Endpunkte: Sitzung → CSRF/Herkunft (403, vor dem Laden).

| Methode und Pfad | Recht | Admin | Admin/A | Op | Op/A | Beob | anonym |
|---|---|---|---|---|---|---|---|
| `GET /api/users[?status=active\|inactive\|deleted]` | `users.manage` | 200 | 403 | 403 | 403 | 403 | 401 |
| `GET /api/users/{id}` | `users.manage` | 200/404 | 403 | 403 | 403 | 403 | 401 |
| `POST /api/users` | `users.manage` + `mayAssign` (+ Passwort bei Admin-Zuweisung) | 201 | 403 | 403 | 403 | 403 | 401 |
| `PUT /api/users/{id}` (`display_name`) | `users.manage` + `mayManage` | 200 | 403 | 403 | 403 | 403 | 401 |
| `PUT /api/users/{id}/assignments` | `users.manage` + `mayManage` + `mayAssign`; Passwort, wenn eine gefährliche Rolle dazukommt oder wegfällt; nicht selbst; Invariante | 200 / 409 | 403 | 403 | 403 | 403 | 401 |
| `POST /api/users/{id}/deactivate` · `/activate` | `users.manage` + `mayManage`; nicht selbst; Invariante; gelöscht → 409 | 200 / 409 | 403 | 403 | 403 | 403 | 401 |
| `DELETE /api/users/{id}` | `users.manage` + `mayManage` + Passwort; nicht selbst; Invariante | 204 / 409 | 403 | 403 | 403 | 403 | 401 |
| `POST /api/users/{id}/password-reset` | `users.manage` + `mayManage` + Passwort; nicht selbst | 200 | 403 | 403 | 403 | 403 | 401 |
| `POST /api/users/{id}/2fa-reset` | `users.manage` + `mayManage` + Passwort; nicht selbst | 200 | 403 | 403 | 403 | 403 | 401 |
| `POST /api/users/{id}/sessions/end` | `users.manage` + `mayManage` | 200 | 403 | 403 | 403 | 403 | 401 |
| `POST /api/users/unlock` (vorhanden) | `users.manage` | 200 | 403 | 403 | 403 | 403 | 401 |
| `GET /api/roles` | `users.manage` | 200 | 403 | 403 | 403 | 403 | 401 |
| `GET /api/categories/manage` | `categories.manage` | 200 | 403 | 403 | 403 | 403 | 401 |
| `POST /api/categories` | `categories.manage` | 201 | 403 | 403 | 403 | 403 | 401 |
| `PUT /api/categories/{id}` | `categories.manage` | 200/404 | 403 | 403 | 403 | 403 | 401 |
| `DELETE /api/categories/{id}` | `categories.manage` | 204/409/404 | 403 | 403 | 403 | 403 | 401 |
| `GET /api/categories?permission=…` (vorhanden) | Scope des angefragten Rechts | unverändert |
| `PUT /api/auth/profile` | Sitzung (eigenes Konto), nicht bei Pflichtwechsel | 200 | 200 | 200 | 200 | 200 | 401 |
| `POST /api/auth/password` | Sitzung + `current_password` | 200 | 200 | 200 | 200 | 200 | 401 |
| `GET /api/auth/sessions` | Sitzung, nur eigene | 200 | 200 | 200 | 200 | 200 | 401 |
| `DELETE /api/auth/sessions/{id}` | Sitzung, nur eigene (fremde → 404) | 204 | 204 | 204 | 204 | 204 | 401 |
| `POST /api/auth/sessions/end-others` | Sitzung | 200 | 200 | 200 | 200 | 200 | 401 |

- Bei Verwaltungsendpunkten mit `{id}` gilt: erst das Recht `users.manage` (403 ohne Laden – der Bereich ist global,
  es gibt keine Existenz zu verbergen), dann Laden (404), dann `mayManage` (403), dann Zustand (409).
- Benutzer mit `password_must_change = 1` bekommen auf alle Endpunkte außer `me`, `password`, `logout` 403 bzw.
  über leere Rechte dieselbe Antwort wie ein Benutzer ohne Recht.

### 4.3 Ablauf am Beispiel `PUT /api/users/{id}/assignments`

1. `SessionAuth::authenticate()` → 401. 2. `CsrfGuard::check()` → 403. 3. `require(grants, ManageUsers)` → 403.
4. Körper: `{assignments: [...], current_password?: string}` → `AssignmentValidator` → 422.
5. Ziel laden (`findById`, auch gelöscht) → 404; gelöscht → 409; Ziel = Handelnder → 409.
6. `mayManage(actor, grantsOf(target))` → 403; je neue/geänderte Zuweisung `mayAssign` → 403.
7. Kommt eine Rolle mit gefährlichem Recht hinzu oder fällt weg: `confirmPassword()` → 403/429.
8. `immediate()`: alte Zuweisungen lesen → gezielt ersetzen (Zeilen löschen/anlegen, keine Tabelle leeren) →
   `AdminInvariant::assertHeld()` (409) → Audit `user.assignments_changed` mit alt → neu
   (`user:7 jana: Operator [NAS, Web] → Operator [alle], Beobachter [alle]`).
9. Antwort: Benutzer über `UserPresenter`.

### 4.4 Antworten (Feld-Allowlist)

```json
{
  "id": 7, "username": "jana", "display_name": "Jana K.", "status": "active",
  "totp_enabled": true, "password_change_required": false, "locked": false,
  "last_login_at": "2026-10-09T07:12:00+00:00", "created_at": "2026-10-01T10:00:00+00:00",
  "session_count": 2,
  "assignments": [
    {"role_id": 2, "role": "Operator", "all_categories": false,
     "categories": [{"id": 3, "name": "NAS"}], "effective": true}
  ]
}
```

- `status` ∈ `active|inactive|deleted`; `effective = false` bei Zuweisung ohne Kategorie und ohne Flag (E9).
- `POST /api/users` → 201 `{user: …, initial_password: "abcd-efgh-…"}`; `password-reset` → 200
  `{initial_password: …, expires_at: …}`. Sonst nie ein Passwort, nie `password_hash`, `totp_secret_enc`,
  Wiederherstellungscodes, `token_hash`.
- `GET /api/roles` → `{roles: [{id, name, builtin, permissions: [...]}], permissions: [{key, dangerous, scoped}]}`.
- Texte (`display_name`, `user_agent`, Kategorienamen) durch `SecretMasker::mask()`; `Cache-Control: no-store`.

### 4.5 Audit-Einträge (`target` nie mit Passwort, Code oder Hash)

| Aktion | `target` |
|---|---|
| `user.created` | `user:<id> <name> (Operator [NAS], Beobachter [alle])` |
| `user.renamed` | `user:<id> <name>: Anzeigename geändert` (ohne Werte) |
| `user.assignments_changed` | `user:<id> <name>: <alt> → <neu>` |
| `user.deactivated` / `user.activated` / `user.deleted` | `user:<id> <name>` |
| `user.password_reset` (Admin) / `user.password_changed` (selbst) | `user:<id> <name>` |
| `user.2fa_reset` | `user:<id> <name>` |
| `user.sessions_ended` | `user:<id> <name> (n Sitzungen)` |
| `session.ended` (selbst) | `session:<id>` |
| `auth.reauth_failed` | `<name>` (des Handelnden) |
| `category.created` / `category.renamed` / `category.deleted` | `category:<id> <Name>` / `… Alt → Neu` / `… (Zuweisungen: n, Freigaben: m)` |

Abgelehnte Anfragen (403/409/422) werden nicht protokolliert, außer fehlgeschlagener Passwort-Bestätigung.

### 4.6 Grenzen (Rate-Limits)

| Aktion | Grenze | Zählung |
|---|---|---|
| Passwort-Bestätigung, Passwortwechsel | Sperre wie Anmeldung (Benutzer + IP) | `LoginThrottle` |
| `POST /api/users` | 30 je Handelndem und Stunde → 429 | Audit-Einträge `user.created` des Handelnden (Index aus 3.1) |
| `password-reset`, `2fa-reset` | 20 je Handelndem und Stunde → 429 | Audit |
| Kategorie anlegen/umbenennen/löschen | 60 je Stunde → 429 | Audit |
| Körpergröße | 8 KiB (`JsonBody`) | – |

Zählen über das Audit-Log braucht keine neue Tabelle; da Schreiben und Audit in einer Transaktion stehen, zählt nur,
was wirklich passiert ist.

---

## 5. Sicherheitsprüfung des Entwurfs

| Frage | Antwort |
|---|---|
| Wo entstehen Geheimnisse? | (1) Einmalpasswort: im Server (`OneTimePassword::generate()`), einmal in der Antwort, gespeichert nur als Argon2id-Hash. (2) Neues Passwort und `current_password`: im Anfragekörper, nur `#[\SensitiveParameter]`, nie gespeichert außer als Hash. (3) Sitzungs-Token bei Passwortwechsel: wie bei der Anmeldung (`SessionManager::start()`). Kein neues `_enc`-Feld. |
| Wo verlassen Daten das System? | API-Antworten (Feld-Allowlist im `UserPresenter`, maskiert, `no-store`), Audit-Log (§4.5, ohne Werte), `error_log` (nur `ErrorLog::unexpected()`), Oberfläche (Einmalpasswort nur im Arbeitsspeicher der Ansicht). Fremde IPs/Browser verlassen die Verwaltung nie (nur Anzahl). |
| Wo wird geprüft, wer was darf? | Jeder Endpunkt: Sitzung → CSRF → `require(users.manage \| categories.manage)` ohne Kategorie (gefährlich, also nur uneingeschränkt) → Laden → `GrantPolicy::mayManage/mayAssign` → Passwort-Bestätigung bei gefährlichen Aktionen → Schreiben mit `AdminInvariant` in einer Transaktion. Eigene Konto-Endpunkte: Sitzung + CSRF, Bezug immer `session.userId`, nie eine ID aus der Anfrage (Ausnahme Sitzungs-ID, geprüft mit `AND user_id = :u`). |
| Fail-closed? | Leere/gelöschte Kategorieliste = nichts; Pflichtwechsel = keine Rechte; gelöscht = inaktiv (Trigger); „alle“ nur per Flag, Widerspruch per Trigger unmöglich; letzte Admin-Rechte nicht entziehbar. |
| Neue Ausgabestellen und Leak-Tests | Benutzerliste/-detail, Anlegen (Einmalpasswort nur dort), Passwort-Reset, Rollenliste, Sitzungsliste, Kategorien-Übersicht, Validierungs- und 409-Meldungen, Audit-Ziele. Leak-Test: Test-Geheimnis als Anzeigename, als `current_password`, als `new_password`, als Benutzer-Agent; das Einmalpasswort darf außer in genau der einen Antwort nirgends stehen (Audit, `error_log`, Rohspalten, weitere Antworten, Trace mit `zend.exception_ignore_args=0`). |

---

## 6. Folgen für andere Teile

- **Scheduler (H1):** keine Änderung; deaktivierte, gelöschte und Benutzer mit Pflichtwechsel haben keine Rechte,
  ihre wartenden manuellen Läufe enden `skipped`. Test in S8.
- **Phase 4 (0004):** `jobs.edit_shell` bleibt gefährlich, also nur über eine uneingeschränkte Admin-Zuweisung.
  Live-Log (SSE) prüft Rechte periodisch neu (E4.4). Neue Shell-Rechte → §E2-Katalog und `isDangerous()`.
- **API-Tokens (Phase 8):** Soft-Delete löscht Tokens, Deaktivieren macht sie wirkungslos.
- **`user:create`** (CLI) bleibt, legt weiterhin „alle Kategorien“ an; neu optional `--must-change` nicht nötig
  (Admin vergibt das Passwort am Terminal selbst).

---

## 7. Oberfläche

Stil wie Einstellungen und Audit-Log: Karten (`card`), Tabellen in `table-wrap`, Formulare mit `FormField`,
zerstörende Aktionen über `Confirm` mit Fokus auf „Abbrechen“. Begriffe sachlich (Benutzer, Rolle, Kategorie,
Zuweisung, Sitzung), keine Bahn-Bildsprache. Rechte in der Oberfläche nur als Bedienkomfort.

| Route | Screen | Inhalt |
|---|---|---|
| `#/benutzer` | **Benutzerliste** (Menü „Benutzer & Rollen“, nur `users.manage` uneingeschränkt) | Tabelle BENUTZER · ANZEIGENAME · ROLLEN · 2FA · STATUS · LETZTE ANMELDUNG; Filter Aktiv/Deaktiviert/Gelöscht; Marker „wirkungslose Zuweisung“, „Passwortwechsel offen“, „gesperrt“; Schaltfläche „Benutzer anlegen“; Reiter „Rollen“ |
| `#/benutzer/rollen` | **Rechte-Matrix** (nur lesend) | Zeilen = Rechte mit deutscher Bezeichnung, Spalten = Rollen; gefährliche Rechte mit Kennzeichnung „gefährlich – nur für alle Kategorien“; Hinweis, dass Rollen im MVP fest sind |
| `#/benutzer/neu` | **Benutzer anlegen** | Benutzername, Anzeigename, Zuweisungs-Editor; nach dem Speichern Karte „Einmalpasswort“ (einmal sichtbar, Kopieren-Knopf, Ablaufdatum, Hinweis „wird nicht erneut angezeigt“); beim Verlassen aus dem Zustand gelöscht, nie in URL/Storage |
| `#/benutzer/{id}` | **Benutzer bearbeiten** | Karte „Stammdaten“ (Anzeigename; Benutzername nur Text); Karte „Rollen und Kategorien“: je Zuweisung Rolle (Auswahl), „Alle Kategorien“ (Kontrollkästchen) **oder** Kategorienliste (Kontrollkästchen), Warnung bei gefährlicher Rolle, Zuweisung entfernen/hinzufügen, „Speichern“ ersetzt die ganze Liste; Karte „Sicherheit“: 2FA-Status, Sitzungen (Anzahl, „Alle beenden“), „Passwort zurücksetzen“, „2FA zurücksetzen“, „Sperre aufheben“; Karte „Konto“: Deaktivieren/Aktivieren, Löschen. Aktionen mit Passwort-Bestätigung öffnen `Confirm` mit Passwortfeld (`type="password"`, `autocomplete="current-password"`), Folgen im Text („Alle Sitzungen von jana enden. jana richtet 2FA neu ein.“). Beim eigenen Konto: Rollen/Konto/Sicherheit deaktiviert mit Hinweis auf „Mein Konto“ |
| `#/kategorien` | **Kategorien** (Menüpunkt nur mit `categories.manage`) | Tabelle NAME · JOBS · ZUWEISUNGEN · FREIGABEN; „Kategorie anlegen“; Umbenennen inline; „Löschen“ bei Jobs > 0 deaktiviert mit Hinweis; `Confirm` nennt Folgen („2 Zuweisungen verlieren diese Kategorie, 1 davon wird wirkungslos; 1 Freigabe interner Ziele wird gelöscht.“) |
| `#/konto` (Erweiterung) | **Mein Konto** | Karten „Anzeigename“, „Passwort ändern“ (aktuelles, neues, wiederholen), „Sitzungen“ (Tabelle BROWSER · ADRESSE · ANGEMELDET · ZULETZT AKTIV, „diese Sitzung“, Beenden je Zeile, „Alle anderen beenden“) neben der vorhandenen 2FA-Karte |
| (nach Anmeldung) | **Passwort festlegen** | Wenn `password_change_required`: nur dieser Screen plus Abmelden, kein Menü; nach Erfolg normale Oberfläche |

Feldfehler aus 422 am Feld (`aria-describedby`); 409-Meldungen (letzter Admin, Kategorie enthält Jobs) als `Alert`
über dem Formular. Tabellen auf Handybreite scrollbar im Container. Rechte-Bezeichnungen und „gefährlich“ kommen aus
`GET /api/roles`; deutsche Texte je Rechteschlüssel in `lib/permissions.ts`.

---

## 8. Umsetzungsreihenfolge

Jeder Schritt endet mit grünem `composer check` (Frontend: `npm run build` + Typprüfung) und eigenem Commit.

| # | Agent | Inhalt | Abnahme / Tests | Skill |
|---|---|---|---|---|
| S1 | sicherheit | Migration 0009 (§3.1, nächste freie Nummer), `Permission::ManageCategories` (gefährlich), `GrantPolicy`, `AdminInvariant` + `LastAdminRemoved`, `AssignmentValidator`/`Assignment`, `grantsFor()` leer bei `password_must_change` | Migrationstest: zweimal einspielen ändert nichts, nur Admin hat `categories.manage`; Trigger: `all_categories=1` + Zeile scheitert (beide Richtungen), gelöscht + aktiv scheitert; NOCASE-Index lehnt `nas`/`NAS` ab; Eigenschaftstest `mayAssign` gegen `can()` über alle Rechte × {alle, A, B, ohne}; `mayManage` mit künstlichen Rollen; Invariante: 1 Admin → herabstufen/deaktivieren/löschen scheitert, 2 Admins → eins geht, zweites nicht; Pflichtwechsel → `grantsFor() === []` | mer-security §4, mer-storage §2/§3 |
| S2 | sicherheit | `OneTimePassword` (Sealed), `AuthService::confirmPassword()`/`changeOwnPassword()`, Ablauf `password_expires_at` in der Anmeldung, `/me` mit `password_change_required`, `SessionManager` (Browser/IP, `listForUser`, `endById`, `endOthers`) | Darstellungs-Test `OneTimePassword` (print_r, var_dump, var_export, json_encode, serialize, `(array)`); Entropie/Alphabet; abgelaufenes Einmalpasswort → gleiche Meldung + Fehlversuch; falsche Bestätigung zählt für die Sperre, 429 mit `Retry-After`; Passwortwechsel ersetzt Sitzung (alte ungültig), beendet andere, löscht Pflicht; `user_agent` > 120 Zeichen/Steuerzeichen gekürzt und gesäubert | mer-security §2/§11/§12 |
| S3 | backend | `UserRepository` (Liste, Detail, Zuweisungen gezielt ersetzen, aktiv/inaktiv, Soft-Delete), `UserPresenter`, lesende API `GET /api/users`, `/api/users/{id}`, `/api/roles`; `unlock` auf CSRF-vor-Recht umstellen | Rollen-Matrix §4.2 (Admin, Admin/A, Op, Op/A, Beob, anonym); Feld-Allowlist mit Reihenfolge; kein `password_hash`/`totp`/`token_hash` in der Antwort; `effective=false` nach Kategorie-Löschung; Roundtrip über neue `Connection` | mer-security §1/§5, mer-storage §8 |
| S4 | backend | `UserAdminService::create/rename/replaceAssignments/setActive/delete`, `POST/PUT/DELETE /api/users…`, `deactivate/activate`, Audit, Grenzen §4.6 | Rollen-Matrix; CSRF fehlt/falsch/fremder Origin → 403 ohne Änderung; Selbstaktionen → 409; letzter Admin (auch zwei parallele Prozesse, die sich gegenseitig herabstufen → genau einer scheitert); Rechteausweitung (künstlicher Handelnder vergibt Recht, das er nicht hat → 403); Admin-Zuweisung beschränkt → 422; leere Kategorieliste ohne Flag → 422; Soft-Delete: Zeile bleibt, Audit-Bezug bleibt, Sitzungen/Tokens/Codes/Zuweisungen weg, Name nicht neu vergebbar, Anmeldung scheitert; Einmalpasswort nur in der 201-Antwort; 31. Anlage/Stunde → 429 | mer-security §4/§14 |
| S5 | sicherheit | `resetPassword`, `resetTwoFactor`, `endSessions`, Endpunkte §4.2 mit Passwort-Bestätigung | Ziel-Sitzungen enden; 2FA-Felder und Codes leer, Passwort unverändert; Reset eines Admins durch Admin ok, durch künstlichen Handelnden mit weniger Rechten 403; Leak: Einmalpasswort nicht im Audit, `error_log`, Rohspalten, Trace; erledigt Phase-1-Punkt „2FA-Reset durch einen Administrator“ | mer-security §2/§12/§14 |
| S6 | backend | `AccountController`: `PUT /api/auth/profile`, `POST /api/auth/password`, `GET/DELETE /api/auth/sessions…`, `end-others`; Pflichtwechsel sperrt alles außer `me`/`password`/`logout` | Fremde Sitzungs-ID → 404 und bleibt bestehen; `token_hash` nie in der Antwort; Pflichtwechsel: Job-Liste, 2FA-Setup, Profil → 403; Anzeigename mit Steuerzeichen/Leerzeichen am Rand → 422; Leak mit Test-Geheimnis als Anzeigename und Benutzer-Agent (maskiert) | mer-security §1/§2 |
| S7 | backend | `CategoryService` + `CategoryController` (§4.2), CLI `category:rename`/`category:delete` mit derselben Prüfung | Rollen-Matrix (nur `categories.manage`); löschen mit Job → 409 und nichts gelöscht; löschen ohne Job: Freigaben der Kategorie weg (nicht global), `user_role_categories` weg, Op/A sieht danach nichts (nie alles); umbenennen: Op/A sieht Jobs weiter, Freigabe gilt weiter; doppelt in anderer Schreibung → 422; Audit mit Anzahlen | mer-security §5, mer-storage §2 |
| S8 | tester | Gesamtsuite Phase 4a | Rollen-Matrix aller neuen Endpunkte; IDOR (Benutzer-/Sitzungs-/Kategorie-IDs fremd oder nicht vorhanden); Rechteausweitung; letzter Admin; H1 (deaktiviert/gelöscht/Pflichtwechsel zwischen Einreihen und Übernahme → `skipped`); Leak-Test aller Ausgabestellen §5 inkl. `zend.exception_ignore_args=0`; Roundtrip (Zuweisungen, Soft-Delete, Pflichtwechsel) über neue `Connection`; Mutation: `AdminInvariant`-Aufruf oder `mayAssign` entfernen → Test fällt | alle |
| U1 | frontend | Menü „Benutzer & Rollen“ aktiv (nur `users.manage`), Menü „Kategorien“ (nur `categories.manage`), Routen, Typen, Rechte-Bezeichnungen, Benutzerliste, Rechte-Matrix | Ohne Recht kein Menüpunkt und keine API-Aufrufe; Handybreite; Build ohne CSP-Fehler | mer-ui §5 |
| U2 | frontend | Benutzer anlegen/bearbeiten mit Zuweisungs-Editor, Einmalpasswort-Karte | „Alle Kategorien“ und Liste schließen sich aus; Admin-Rolle erzwingt „alle“; Einmalpasswort nur einmal, nach Verlassen weg, nie im Storage; 422 am Feld, 409 als Alert | mer-ui §3/§5 |
| U3 | frontend | Sicherheits- und Kontoaktionen mit `Confirm` + Passwortfeld | Fokus auf „Abbrechen“; eigene Konto-Aktionen deaktiviert mit Hinweis; Fehlversuch zeigt Servermeldung, 429 mit Wartezeit | mer-ui §3 |
| U4 | frontend | Kategorien-Seite | Löschen bei Jobs deaktiviert; Folgen im `Confirm`; Umbenennen inline mit Feldfehler | mer-ui |
| U5 | frontend | Mein Konto (Anzeigename, Passwort, Sitzungen), Screen „Passwort festlegen“ | Pflichtwechsel zeigt nur diesen Screen; nach Wechsel neues CSRF-Token aus `/me`; Sitzung beenden mit `Confirm` | mer-ui §3 |
| R | sicherheit | Review Phase 4a nach mer-security §15 | alle Befunde behoben oder von Alex akzeptiert | – |

---

## 9. Offene Entscheidungen für Alex

Bis zur Entscheidung gilt die Empfehlung (fett).

| # | Frage | Optionen | Empfehlung |
|---|---|---|---|
| B1 | Eigene Rollen im MVP? | feste drei Rollen · eigene Rollen mit Editor | **Feste Rollen**, mehrere Zuweisungen je Benutzer mit Kategorien; eigene Rollen nach dem MVP (Modell vorbereitet, E1) |
| B2 | Benutzer löschen | Soft-Delete · hart löschen (Audit/Verlauf verlieren den Bezug) · nur deaktivieren | **Soft-Delete** plus Deaktivieren (E5) |
| B3 | Erstes Passwort | Einmalpasswort + Pflichtwechsel · Admin tippt Passwort · Einladungslink | **Einmalpasswort + Pflichtwechsel** (E6); Einladungslink nach Phase 6 (E-Mail) |
| B4 | Gültigkeit Einmalpasswort | 24 h · 72 h · 7 Tage | **7 Tage** (NAS-Betrieb, Übergabe dauert); Admin kann jederzeit neu erzeugen |
| B5 | Eigenes Passwort bei gefährlichen Verwaltungsaktionen | ja · nein · Sudo-Fenster | **Ja, je Aktion** (E7) |
| B6 | Kategorie mit Jobs löschen | ablehnen · Jobs „ohne Kategorie“ · Jobs mitlöschen | **Ablehnen (409)** (E9) |
| B7 | Recht für Kategorien | neues `categories.manage` (gefährlich) · `users.manage` mitbenutzen | **Neues Recht**, per Migration nur an Admin |
| B8 | Sitzungsübersicht mit Browser und IP speichern | ja, nur für den Inhaber · nur Zeiten · keine Übersicht | **Ja, nur für den Inhaber sichtbar**; Admin sieht bei anderen nur die Anzahl |
| B9 | 2FA für Admins erzwingen | jetzt · später als Einstellung · nie | **Später als Einstellung** (lockert nichts, verschärft; eigener Schlüssel in `settings`); im MVP Hinweis in der Benutzerliste |
| B10 | Darf ein Admin einen anderen Admin zurücksetzen, deaktivieren, löschen? | ja mit Bestätigung · nein | **Ja**, mit Passwort-Bestätigung und Audit; die Invariante schützt den letzten |
| B11 | Benutzername änderbar? | nein · ja mit Audit | **Nein** (Anmeldename, Sperr-Bezug, Audit-Ziel) |
| B12 | Zuweisungen, die nach Kategorie-Löschung leer sind | behalten und markieren · automatisch löschen | **Behalten und als „wirkungslos“ markieren** (sichtbar statt still; gewähren nichts) |
| B13 | Admin-Passwort-Reset und 2FA | 2FA bleibt · 2FA wird mit zurückgesetzt | **2FA bleibt** (wie `user:password`); 2FA-Reset ist eine eigene Aktion |
| B14 | Gelöschte Benutzer: Anzeigename | behalten · anonymisieren | **Behalten** für Audit/Verlauf; Anonymisieren (Datenschutz) als spätere Aktion |

---

## 10. Regeländerungen für `CLAUDE.md`, Skills und Hook

Vom Koordinator einzutragen, spätestens mit dem Schritt, der die Regel zuerst umsetzt (Pflicht „Skill mitpflegen“).

**`CLAUDE.md`, Sicherheitsnetz (neue Zeilen):**

1. „Der letzte Admin (aktiv, alle Rechte ohne Kategorie-Beschränkung) bleibt: `AdminInvariant` nach jeder Rechte-,
   Aktiv- oder Löschänderung in derselben Transaktion. Niemand vergibt Rechte, die er nicht hat, niemand verwaltet
   einen Benutzer mit mehr Rechten, niemand ändert über die Verwaltung die eigenen Rollen.“ (`mer-security`)
2. „Benutzer werden nie gelöscht, nur deaktiviert oder als gelöscht markiert (`deleted_at`); Passwörter vergibt der
   Server als Einmalpasswort mit Pflichtwechsel, Klartext genau einmal in der Antwort.“ (`mer-security`, `mer-storage`)

**`mer-security`:**

- §4: `categories.manage` als gefährliches Recht; `GrantPolicy::mayAssign/mayManage`; `AdminInvariant`; gefährliche
  Rolle nur mit `all_categories`; Passwort-Bestätigung (`confirmPassword`) für Admin-Zuweisung, Passwort-/2FA-Reset,
  Löschen; Selbstaktionen → 409.
- §2: Pflichtwechsel (`grantsFor()` leer, nur `me`/`password`/`logout`), Ablauf des Einmalpassworts wie falsches
  Passwort; Passwortwechsel ersetzt die Sitzung und beendet die anderen; 2FA-Reset durch Admin beendet Sitzungen.
- §11: `OneTimePassword` in die Liste der Klassen mit Geheimnis (Sealed, Darstellungs-Test).
- §14: neue Audit-Ereignisse aus §4.5.
- Verboten: „`DELETE FROM users` im Anwendungscode“; „leere Kategorieliste beim Speichern einer Zuweisung annehmen“;
  „Rechte des Ziels oder neue Rechte prüfen, ohne dass der Handelnde sie selbst hat“; „Einmalpasswort im Audit, Log
  oder einer zweiten Antwort“.

**`mer-storage`:** §2 Trigger „alle ⇒ keine Einzelkategorien“ und „gelöscht ⇒ inaktiv“; §3 `roles.is_builtin`;
Kategorienamen eindeutig per NOCASE-Index; Kategorie löschen nur ohne Jobs; Grenzen über das Audit-Log zählen.

**`mer-ui`:** §3 Einmalpasswort nur im Arbeitsspeicher der Ansicht, nie Storage/URL, nach Verlassen weg;
Passwort-Bestätigung im `Confirm`; Screen „Passwort festlegen“ bei Pflichtwechsel; §5 Rechte-Matrix nur lesend,
gefährliche Rechte gekennzeichnet.

**Hook (`rule-router.js`):** Muster `DELETE\s+FROM\s+users\b` in `src/` als Verstoß (außer in Tests).
