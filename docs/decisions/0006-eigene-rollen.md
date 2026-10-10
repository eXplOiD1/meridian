# 0006 – Eigene Rollen und anpassbare Rechte (Phase 5)

Status: **Entwurf** (architekt, 10.10.2026). Auftrag Alex: „Rechte sollen anpassbar sein und es muss möglich sein, neue
Rollen hinzuzufügen.“ Löst ADR 0005 E1/B1 (drei feste Rollen) ab; alle übrigen Entscheidungen aus 0005 gelten weiter.
Offene Punkte stehen mit Empfehlung in §9 (F1–F9). Bis Alex entscheidet, gilt jeweils die Empfehlung.

Dieser Entwurf ändert keinen Produktcode. Migration und Interfaces stehen hier als Skizze; die Datei unter
`migrations/` legt erst R1 an (der `Migrator` spielt alles dort sofort ein).

---

## 1. Ausgangslage

| Vorhanden | Folgerung |
|---|---|
| `roles (id, name UNIQUE, is_builtin)`, `role_permissions (role_id, permission)` mit CASCADE, `user_roles.role_id … ON DELETE CASCADE` | Datenmodell trägt eigene Rollen schon. Es fehlen: stabile Kennung der Standardrollen, Eindeutigkeit ohne Schreibung, Schutz der Standardrollen in der Datenbank. |
| Rechtekatalog = `Security\Permission` (enum), `isDangerous()` exhaustiv, `isCategoryScoped() = !isDangerous()` | Katalog bleibt im Code (E2). „Nur alle Kategorien“ ist dasselbe wie „gefährlich“, kein zweites Flag. |
| `UserRepository::grantsFor()` liest bei **jeder** Anfrage `user_roles ⋈ role_permissions`; kein Cache in Sitzung, `RoleCatalog` oder Prozess; SSE prüft alle 15 s neu; `DbRunAuthorizer` beim Übernehmen | Eine Rechteänderung an einer Rolle wirkt ab der nächsten Anfrage für alle Inhaber, auch in offenen Sitzungen (E6). Nichts zu invalidieren. |
| `GrantPolicy::mayAssign/mayManage`, `AdminInvariant` (alle `Permission::cases()` ohne Kategorie), `recheck()` am Anfang jeder Verwaltungstransaktion | Baut auf `can()` auf, nicht auf Rollennamen — gilt für eigene Rollen unverändert. Neu nur `mayDefineRole` (E4). |
| Rollen **per Name** angesprochen: `AdminBootstrapCommand` (`'Admin'`), `user:create --role=`, Migrationen 0007/0009/0011 (`r.name = 'Admin'`) | Wäre „Admin“ umbenennbar, liefe eine spätere Migration für ein neues Recht ins Leere → niemand hat mehr alle Rechte → `AdminInvariant` blockiert jede Rechteänderung, der CLI-Notausgang findet die Rolle nicht. Deshalb `builtin_key` (E1). |
| `AuthController::profile()` gibt `role` **unmaskiert** aus (bisher feste Namen) | Rollennamen werden freier Text → in `/me` durch `SecretMasker::mask()` (Regel 4; `UserPresenter` und Audit maskieren schon). |
| `AssignmentValidator::MAX_ASSIGNMENTS = 3` („je Rolle höchstens eine“), Oberfläche hart `value.length < 3` | Mit eigenen Rollen zu eng → 10, Grenze vom Server (F7). |
| Oberfläche: `#/benutzer/rollen` Rechte-Matrix nur lesend, `AssignmentEditor`, `PasswordConfirm`, `Confirm`, `lib/permissions.ts` | Matrix wird Einstieg zum Rollen-Editor (§7). |

---

## 2. Entscheidungen

### E1 – Datenmodell: eine Migration, Standardrollen über `builtin_key` geschützt

- `roles.builtin_key` ∈ `admin|operator|observer` (NULL bei eigenen Rollen), eindeutig. Code, CLI und **jede künftige
  Migration** sprechen Standardrollen nur noch über diesen Schlüssel an, nie über den Namen.
- `roles.description` (optional, ≤ 200 Zeichen, F9). Rollennamen eindeutig ohne Schreibung (`roles_name_nocase`).
- Trigger als zweite Schicht (der Code prüft zuerst und liefert die Meldung): Standardrolle nicht löschbar, Name und
  Kennung einer Standardrolle unveränderlich, keine neue Standardrolle per `INSERT`, Rechte der Admin-Rolle nicht
  entfernbar, nur bekannte Rechte-Schlüssel in `role_permissions` (Liste ≡ `Permission::cases()`, Test wie
  `SettingKey` ≡ CHECK).
- Kein neues `_enc`-Feld: Rollen enthalten keine Geheimnisse.

**Standardrollen (Empfehlung F1/F2):**

| Rolle | Name | Rechte | Löschen |
|---|---|---|---|
| Admin | fest | **fest: immer alle Rechte** (neue Rechte per Migration) | nie |
| Operator, Beobachter | fest | **änderbar** wie eigene Rollen | nie |
| eigene Rolle | änderbar | änderbar | nur ohne Zuweisungen (E5) |

Begründung: Die Admin-Rolle ist der Anker für `AdminInvariant`, den CLI-Notausgang (`user:create --role=Admin`,
`admin:bootstrap`) und neue Rechte aus Migrationen; wäre sie änderbar, könnte nach einem Update niemand mehr „alle
Rechte“ haben. Operator/Beobachter freizugeben ist genau, was Alex will („Rechte anpassbar“); ihr Name bleibt fest,
weil CLI, Doku und ältere Audit-Einträge ihn nennen.

Verworfen: (a) Standardrollen komplett sperren, Anpassung nur über Kopien – sicher, aber Alex müsste alle Benutzer
umhängen, um Operator zu ändern. (b) Alles inkl. Admin änderbar – siehe oben, der Notausgang hinge an der Konfiguration.
(c) Admin-Rechte nicht speichern, sondern im Code als „alle“ ausrechnen – zweiter Rechteweg neben `role_permissions`,
`grantsFor()` müsste ihn kennen; die gespeicherten Zeilen plus Trigger sind einfacher und bleiben eine Quelle.

### E2 – Rechtekatalog bleibt im Code, Rollen sind nur Zuordnungen

- Recht-Schlüssel, Bezeichnung, „gefährlich“ (= nur „alle Kategorien“) stehen nur in `Security\Permission`. Die
  Datenbank speichert nur Rolle → Schlüssel. Kein Recht lässt sich in der Oberfläche anlegen.
- **Neues Recht `roles.manage`** (`Permission::ManageRoles`, gefährlich, F3): Rollen anlegen, umbenennen, Rechte setzen,
  löschen. Per Migration nur an Admin. Getrennt von `users.manage`, weil eine Rollenänderung die Rechte **aller**
  Inhaber auf einmal ändert (wie `categories.manage`, 0005 B7). Zuweisen von Rollen an Benutzer bleibt `users.manage`.
- Neues Recht künftig: `Permission`-Fall + `isDangerous()`-Zweig + Migration (Admin über `builtin_key`, Trigger-Liste
  neu anlegen) + Bezeichnung in `lib/permissions.ts`. Nie automatisch an Operator, Beobachter oder eigene Rollen.
- Folge für eigene Rollen mit „allen Rechten“: nach einem Update mit neuem Recht sind ihre Inhaber keine Admins im Sinn
  der Invariante mehr. `migrate` meldet deshalb eine Warnung, wenn danach `AdminInvariant::countAdmins() === 0`
  („Kein Administrator mit allen Rechten. `user:create --role=Admin` …“).

### E3 – Gefährliche Rechte nur für Rollen, die ausschließlich „alle Kategorien“ zugewiesen sind

- Eine Rolle mit mindestens einem gefährlichen Recht (`Role::isDangerous()`) darf nur mit `all_categories = true`
  zugewiesen sein (gilt schon im `AssignmentValidator`).
- Neu beim **Rechte setzen**: Kommt ein gefährliches Recht dazu und hat die Rolle beschränkte Zuweisungen → 409
  „Diese Rolle ist n-mal auf Kategorien beschränkt zugewiesen. Gefährliche Rechte gelten nur für alle Kategorien:
  zuerst die Zuweisungen auf ‚Alle Kategorien‘ umstellen oder entfernen.“ (mit `{error, restricted_assignments: n}`).
- Neu in `UserAdminService` **in der Transaktion**: „gefährliche Rolle + beschränkt“ noch einmal mit den frisch
  gelesenen Rollenrechten prüfen (sonst Wettlauf mit einer gleichzeitigen Rollenänderung). `RoleGrant::allows()` bleibt
  dritte Schicht (gefährlich + beschränkt gewährt nie).

### E4 – Keine Rechteausweitung, keine Selbständerung, letzter Admin

Handelnder = angemeldeter Benutzer mit `roles.manage` (uneingeschränkt, da gefährlich).

1. **Nur Rechte setzen, die man selbst hat** (`GrantPolicy::mayDefineRole`): für **jedes** Recht in `alt ∪ neu` der
   Rolle `can(actor, p, null)`. Also auch Entfernen nur, wenn man das Recht selbst uneingeschränkt hat. Gilt für
   Anlegen (alt = ∅), Rechte setzen und Löschen. Sonst 403.
2. **Niemanden über die Rolle verwalten, der mehr hat** (F8): für jeden Inhaber der Rolle (auch inaktiv, über
   `assignedGrantsFor()`) `mayManage(actor, inhaber)`. Eine Rollenänderung ist eine Rechteänderung an allen Inhabern.
   Sonst 403 „Diese Rolle hat ein Benutzer mit Rechten, die du nicht hast.“
3. **Keine Änderung an der eigenen Rolle** (F5): Ist dem Handelnden die Rolle zugewiesen (irgendein Geltungsbereich),
   sind Rechte setzen und Löschen → 409 „Diese Rolle ist dir selbst zugewiesen. Rechte ändert ein anderer
   Administrator.“ Name/Beschreibung bleiben erlaubt (ändern keine Rechte, wie der Anzeigename in 0005 E4.1). Damit
   kann sich niemand selbst aussperren oder unbemerkt erweitern.
4. **Admin-Rolle**: Rechte setzen → 409 „Die Rolle Admin hat immer alle Rechte.“; löschen → 409.
5. **Letzter Admin**: `AdminInvariant::assertHeld()` nach **jeder** Rollenänderung (Rechte setzen, löschen) in
   derselben `immediate()`-Transaktion → `LastAdminRemoved` → Rollback → 409. Trifft z. B. eine eigene Rolle mit allen
   Rechten, die der einzige Admin-Weg ist.
6. **Frisch in der Transaktion** (wie `recheck()`, Review 4a N1): Rechte des Handelnden neu laden, `roles.manage`,
   `mayDefineRole`, Inhaber-`mayManage`, Selbstbezug und beschränkte Zuweisungen (E3) **innerhalb** der Transaktion
   wiederholen. Wer zwischen Laden und Schreiben herabgestuft wurde, schreibt nichts.
7. **Passwort-Bestätigung** (F6, wie 0005 E7): wenn ein gefährliches Recht dazukommt oder wegfällt (Anlegen mit
   gefährlichem Recht eingeschlossen). `AuthService::confirmPassword()` **vor** der Transaktion; 422 fehlt, 403 falsch,
   429 gesperrt. Kommt in der Transaktion unerwartet ein gefährliches Recht hinzu (Wettlauf) → 409 „inzwischen geändert“.

### E5 – Rolle löschen nur ohne Zuweisungen

- `DELETE /api/roles/{id}`: Standardrolle → 409; Zuweisungen > 0 (inkl. inaktiver Benutzer; gelöschte haben keine) →
  409 `{error, assignments: n}` „Rolle ist n Benutzern zugewiesen. Zuweisungen zuerst ändern.“; sonst löschen
  (`role_permissions` per CASCADE) + Audit. Die Kaskade `user_roles.role_id` bleibt als letzte Sicherung (verengt nur).
- Verworfen für jetzt (F4): „Umhängen auf Rolle X“ in einem Schritt — wäre eine Massen-Zuweisung mit `mayAssign` je
  Benutzer, Passwort-Regeln je Benutzer und vielen Fehlerfällen. Die Oberfläche verlinkt stattdessen die gefilterte
  Benutzerliste (`GET /api/users?role_id=`).

### E6 – Wirkung sofort, auch für offene Sitzungen

Keine Rechte in Sitzung, Cookie, CSRF-Token oder Prozess-Cache (Prüfung in R1: `grep` auf statische Caches, Test mit
offener Sitzung). Nach dem Commit gilt:

| Ort | Wirkung |
|---|---|
| nächste API-Anfrage eines Inhabers | `grantsFor()` liest die neuen Rechte (Test: Sitzung offen, Recht weg → 403/404) |
| offene Live-Log-Verbindung (SSE) | spätestens nach 15 s `event: end {reason: "forbidden"}` (vorhanden) |
| wartender manueller Lauf | beim Übernehmen `DbRunAuthorizer` → `skipped` (H1, vorhanden) |
| laufender Lauf | läuft zu Ende (war beim Start berechtigt), Abbrechen prüft neu |
| Oberfläche des Inhabers | Menü veraltet bis zum nächsten `/me`; der Server antwortet 403/404. `Layout` lädt `/me` beim Zurückkehren in das Fenster (`visibilitychange`) neu |
| API-Tokens (Phase 8) | handeln mit den Rechten des Benutzers → wirkt ebenso |

### E7 – Audit je Änderung, ohne Werte, die Geheimnisse sein könnten

| Aktion | `target` (maskiert, gekürzt durch `AuditLog`) |
|---|---|
| `role.created` | `role:<id> <Name> [jobs.view, jobs.run]` |
| `role.renamed` | `role:<id> <Alt> → <Neu>`; bei Beschreibung nur „Beschreibung geändert“ |
| `role.permissions_changed` | `role:<id> <Name>: +jobs.run −jobs.edit_http (betrifft n Benutzer)` |
| `role.deleted` | `role:<id> <Name>` |

Audit in derselben `immediate()`-Transaktion (scheitert er, Rollback). Abgelehnte Anfragen werden nicht protokolliert,
außer fehlgeschlagener Passwort-Bestätigung (`auth.reauth_failed`, vorhanden). Grenze: 60 Rollenänderungen je
Handelndem und Stunde (Zählung über das Audit-Log, 429 + `Retry-After`), höchstens 50 Rollen insgesamt (409).

---

## 3. Migration `0013_custom_roles.sql` (Entwurf, anzulegen in R1)

Nummer: nächste freie beim Anlegen. Ändert kein bestehendes Recht außer `+roles.manage` für die Admin-Rolle.

```sql
-- Phase 5: eigene Rollen (docs/decisions/0006-eigene-rollen.md, §3). Ergänzt nur; bestehende Rollen, Rechte und
-- Zuweisungen bleiben unverändert (einzige Ausnahme: das neue Recht roles.manage für die Admin-Rolle).

ALTER TABLE roles ADD COLUMN builtin_key TEXT CHECK (builtin_key IS NULL OR builtin_key IN ('admin', 'operator', 'observer'));
ALTER TABLE roles ADD COLUMN description TEXT CHECK (description IS NULL OR length(description) <= 200);

UPDATE roles SET builtin_key = 'admin'    WHERE is_builtin = 1 AND name = 'Admin';
UPDATE roles SET builtin_key = 'operator' WHERE is_builtin = 1 AND name = 'Operator';
UPDATE roles SET builtin_key = 'observer' WHERE is_builtin = 1 AND name = 'Beobachter';

CREATE UNIQUE INDEX roles_builtin_key ON roles (builtin_key);          -- NULL ist in UNIQUE nie gleich
CREATE UNIQUE INDEX roles_name_nocase ON roles (name COLLATE NOCASE);

-- Neues gefährliches Recht nur für die Admin-Rolle (über die Kennung, nie über den Namen).
INSERT INTO role_permissions (role_id, permission)
    SELECT r.id, 'roles.manage' FROM roles r
     WHERE r.builtin_key = 'admin'
       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'roles.manage');

-- Standardrollen: nie löschen, Name und Kennung fest, keine neuen per INSERT.
CREATE TRIGGER roles_builtin_not_deleted BEFORE DELETE ON roles
    WHEN OLD.builtin_key IS NOT NULL OR OLD.is_builtin = 1
BEGIN SELECT RAISE(ABORT, 'Standardrollen lassen sich nicht löschen.'); END;
CREATE TRIGGER roles_builtin_fixed BEFORE UPDATE OF name, builtin_key, is_builtin ON roles
    WHEN (OLD.builtin_key IS NOT NULL OR OLD.is_builtin = 1)
     AND (NEW.name IS NOT OLD.name OR NEW.builtin_key IS NOT OLD.builtin_key OR NEW.is_builtin IS NOT OLD.is_builtin)
BEGIN SELECT RAISE(ABORT, 'Name und Kennung einer Standardrolle sind fest.'); END;
CREATE TRIGGER roles_custom_not_builtin_insert BEFORE INSERT ON roles
    WHEN NEW.builtin_key IS NOT NULL OR NEW.is_builtin <> 0
BEGIN SELECT RAISE(ABORT, 'Neue Rollen sind nie Standardrollen.'); END;
CREATE TRIGGER roles_custom_not_promoted BEFORE UPDATE OF builtin_key, is_builtin ON roles
    WHEN OLD.builtin_key IS NULL AND OLD.is_builtin = 0 AND (NEW.builtin_key IS NOT NULL OR NEW.is_builtin <> 0)
BEGIN SELECT RAISE(ABORT, 'Eine eigene Rolle wird nie zur Standardrolle.'); END;

-- Admin behält alle Rechte (neue kommen per Migration dazu, entfernt wird nie).
CREATE TRIGGER role_permissions_admin_kept BEFORE DELETE ON role_permissions
    WHEN (SELECT builtin_key FROM roles WHERE id = OLD.role_id) = 'admin'
BEGIN SELECT RAISE(ABORT, 'Die Rolle Admin hat immer alle Rechte.'); END;
CREATE TRIGGER role_permissions_no_update BEFORE UPDATE ON role_permissions
BEGIN SELECT RAISE(ABORT, 'Rechte gezielt hinzufügen oder entfernen, nie umschreiben.'); END;

-- Nur bekannte Rechte (Liste ≡ Permission::cases(); neues Recht → Trigger in seiner Migration neu anlegen).
CREATE TRIGGER role_permissions_known BEFORE INSERT ON role_permissions
    WHEN NEW.permission NOT IN ('jobs.view', 'jobs.run', 'jobs.edit_http', 'jobs.edit_shell', 'status_pages.edit',
        'users.manage', 'roles.manage', 'categories.manage', 'network.internal_targets', 'settings.manage', 'shell.targets')
BEGIN SELECT RAISE(ABORT, 'Unbekanntes Recht.'); END;
```

Hinweis: Hat eine Installation einen der drei Namen per Hand geändert (nie über die Anwendung möglich), bleibt
`builtin_key` dort NULL; R1 prüft beim `migrate`, dass genau drei Kennungen gesetzt sind, sonst Abbruch mit Meldung.

---

## 4. Schnittstellen (Skizze)

```php
namespace Meridian\User;

enum BuiltinRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Observer = 'observer';

    /** Nur die Admin-Rolle hat feste Rechte (E1). */
    public function permissionsEditable(): bool;
}

final readonly class Role            // erweitert: builtin bleibt als abgeleitetes Feld für bestehende Aufrufer
{
    /** @param list<Permission> $permissions */
    public function __construct(public int $id, public string $name, public ?BuiltinRole $builtinKey,
        public ?string $description, public array $permissions) {}
    public function isBuiltin(): bool;
    public function isDangerous(): bool;     // vorhanden
}

/** Name 2–40 Zeichen: Buchstaben, Ziffern, Leerzeichen, . _ -; Anfang/Ende Buchstabe oder Ziffer; ablehnen, nie trimmen. */
final class RoleName { public static function isValid(string $name): bool; }

/** Strenge Eingabe: create {name, description|null, permissions: list<string>, current_password?},
 *  rename {name, description|null}, permissions {permissions: list<string>, current_password?}.
 *  Unbekannte Felder, unbekannte oder doppelte Rechte → 422 mit Feldpfad (permissions.3), nie mit Eingabewert. */
final class RoleValidator { /* validateCreate(), validateRename(), validatePermissions() → RoleDraft / list<Permission> */ }

/** Folgen-Vorschau für Editor und Löschen. */
final readonly class RoleUsage
{
    public function __construct(public int $roleId, public int $assignments, public int $restrictedAssignments,
        public int $activeUsers, public bool $heldByActor) {}
}

/** Schreibend; jede Methode: Prüfungen §2 E4 → immediate(): recheck → schreiben → AdminInvariant → Grenze → Audit. */
final class RoleAdminService
{
    /** @param list<Permission> $permissions */
    public function create(Actor $actor, string $name, ?string $description, array $permissions,
        #[\SensitiveParameter] ?string $currentPassword): Role;
    public function rename(Actor $actor, int $roleId, string $name, ?string $description): Role;
    /** @param list<Permission> $permissions ersetzt die ganze Liste (gezielt: fehlende entfernen, neue einfügen) */
    public function setPermissions(Actor $actor, int $roleId, array $permissions,
        #[\SensitiveParameter] ?string $currentPassword): Role;
    public function delete(Actor $actor, int $roleId): void;
    /** @return list<RoleUsage> */
    public function usage(Actor $actor): array;
}

/** Schreibzugriffe ohne eigene Transaktion (der Dienst öffnet immediate()). Lesen bleibt in RoleCatalog. */
final class RoleRepository { /* insert, rename, addPermission, removePermission, delete, countAssignments,
                                countRestrictedAssignments, holders(roleId): list<int>, count() */ }
```

```php
namespace Meridian\Security;

// GrantPolicy, neu:
/**
 * Darf der Handelnde eine Rolle von $old auf $new setzen? Jedes Recht in alt ∪ neu muss er uneingeschränkt haben
 * (`can($actorGrants, $p, null)`), also auch für das Entfernen. Nur auf can() aufgebaut, nie auf Rollennamen.
 * @param list<RoleGrant> $actorGrants @param list<Permission> $old @param list<Permission> $new
 */
public function mayDefineRole(array $actorGrants, array $old, array $new): bool;
/** @throws AccessDenied */
public function requireRoleManager(array $actorGrants): void;   // require(roles.manage) ohne Kategorie
```

`UserRepository::create(…, string $role)` und `RoleCatalog` bekommen `findByName()` (NOCASE) und
`builtin(BuiltinRole)`; `admin:bootstrap` nutzt `builtin(Admin)`, `user:create --role=` nimmt einen Namen (auch eigene
Rollen, NOCASE). `UserAdminService` übernimmt E3 in der Transaktion; `AssignmentValidator::MAX_ASSIGNMENTS = 10`.

---

## 5. Endpunkte und Rechte

Reihenfolge jedes schreibenden Endpunkts: Sitzung (401) → CSRF/Herkunft (403, `JsonReply::csrfFailed()`) →
`require(roles.manage)` ohne Kategorie (403, **vor** Körper und ID: kein 404/422-Orakel) → Körper streng (422) →
Laden (404) → Standardrolle/Admin (409/422) → Selbstbezug (409) → `mayDefineRole` (403) → Inhaber-`mayManage` (403) →
beschränkte Zuweisungen bei neuem gefährlichem Recht (409) → Passwort (422/403/429) → `immediate()` (E4.6) → Antwort.

| Methode und Pfad | Recht / Regeln | Admin | Admin/A | Op | Op/A | Beob | Rollenpfleger¹ | anonym |
|---|---|---|---|---|---|---|---|---|
| `GET /api/roles` (vorhanden, + `description`, `builtin_key`, `max_assignments`) | `users.manage` | 200 | 403 | 403 | 403 | 403 | 403 | 401 |
| `GET /api/roles/manage` | `roles.manage`; je Rolle `assignments`, `restricted_assignments`, `active_users`, `held_by_me`, `permissions_editable`, `deletable` | 200 | 403 | 403 | 403 | 403 | 200 | 401 |
| `POST /api/roles` | `roles.manage` + `mayDefineRole`; Passwort bei gefährlichem Recht; ≤ 50 Rollen | 201 | 403 | 403 | 403 | 403 | 201/403 | 401 |
| `PUT /api/roles/{id}` (`name`, `description`) | `roles.manage`; Standardrolle: Name → 422 am Feld | 200 | 403 | 403 | 403 | 403 | 200 | 401 |
| `PUT /api/roles/{id}/permissions` | `roles.manage` + E3 + E4.1–E4.7 + Invariante | 200/409 | 403 | 403 | 403 | 403 | 200/403 | 401 |
| `DELETE /api/roles/{id}` | `roles.manage` + E5 + `mayDefineRole(alt, ∅)` + nicht selbst | 204/409 | 403 | 403 | 403 | 403 | 204/403 | 401 |
| `GET /api/users?role_id=` (neuer Filter) | `users.manage` (wie Liste) | 200 | 403 | 403 | 403 | 403 | 403 | 401 |

¹ Künstliche eigene Rolle im Test: `roles.manage` + `jobs.view`/`jobs.run`, ohne `users.manage` — darf nur Rollen
definieren, deren Rechte (alt ∪ neu) er selbst hat; zusätzlich ein Rollenpfleger mit `users.manage`, der eine Rolle
eines echten Admins ändern will → 403 (E4.2).

Antworten (Feld-Allowlist, Texte maskiert, `no-store`):

```json
{ "role": { "id": 5, "name": "Ausführer", "description": "Darf Jobs starten, nicht ändern", "builtin": false,
  "builtin_key": null, "permissions": ["jobs.view", "jobs.run"], "permissions_editable": true, "deletable": false,
  "assignments": 4, "restricted_assignments": 3, "active_users": 3, "held_by_me": false } }
```

`permissions` in Katalog-Reihenfolge (`Permission::cases()`), wie im `UserPresenter`. `/api/auth/me`: `role`
maskiert (R3).

---

## 6. Sicherheitsprüfung des Entwurfs

| Frage | Antwort |
|---|---|
| Wo entstehen Geheimnisse? | Keine neuen. `current_password` nur im Anfragekörper (`#[\SensitiveParameter]`, nie gespeichert, nie geloggt). Rollenname/Beschreibung sind keine Geheimnisse, werden aber wie jeder freie Text maskiert. |
| Wo verlassen Daten das System? | Antworten (`RolePresenter` bzw. `UserPresenter::roles()`, Feld-Allowlist, maskiert), `/me` (Rollenname jetzt maskiert), Audit (§E7, maskiert durch `AuditLog`), Validierungs- und 409-Meldungen (feste Texte, nur Anzahlen, nie Eingabe), `error_log` (nur `ErrorLog::unexpected()`). |
| Wo wird geprüft, wer was darf? | `require(roles.manage)` vor Körper/ID; `mayDefineRole` (alt ∪ neu); Inhaber-`mayManage`; Selbstbezug; Admin-Rolle fest; E3; alles in der Transaktion wiederholt; `AdminInvariant` nach dem Schreiben; Trigger als zweite Schicht; `RoleGrant::allows()` als dritte (gefährlich + beschränkt gewährt nie). Zuweisen bleibt `users.manage` mit `mayAssign`, das die **aktuellen** Rollenrechte liest. |
| Fail-closed? | Unbekannter Rechte-Schlüssel wird nie gewährt (`tryFrom`) und nie gespeichert (Trigger). Rolle ohne Rechte gewährt nichts. Gelöschte Rolle → Zuweisung weg (verengt). Leere Kategorieliste bleibt „nichts“. Keine Migration verteilt Rechte an andere Rollen als Admin. |
| Bestandsdaten | Verhalten unverändert: Test vergleicht `grantsFor()` jedes Benutzers vor/nach 0013 (gleich bis auf `+roles.manage` bei Admin-Inhabern) und `AdminInvariant::countAdmins()` (gleich). |
| Neue Ausgabestellen → Leak-Tests | Rollenliste, Verwaltungsliste, Anlegen/Ändern/Löschen, `/me`, Audit, 409/422-Meldungen: Test-Geheimnis als Rollenname und Beschreibung → maskiert; `current_password` nie in Antwort, Audit, `error_log`, Trace (`zend.exception_ignore_args=0`). |

---

## 7. Oberfläche (Meridian-Design, `mer-ui`)

Bestehende Bausteine: `card`, `table-wrap`, `table matrix`, `badge`/`badge--danger`, `FormField`, `Alert`, `Confirm`
(Fokus auf „Abbrechen“), `PasswordConfirm`. Farben, Schriften und Begriffe aus dem Klickdummy; keine neue
Abhängigkeit. Rechte in der Oberfläche nur Bedienkomfort.

| Route | Screen | Inhalt |
|---|---|---|
| Menü „Benutzer & Rollen“ | sichtbar bei `users.manage` **oder** `roles.manage`; Reiter „Benutzer“ nur mit `users.manage` | `canManageRoles()` in `lib/permissions.ts`, Bezeichnung `roles.manage` → „Rollen und Rechte verwalten“ |
| `#/benutzer/rollen` | **Rollen** | Oben Tabelle ROLLE · ART (Badge „Standard“/„Eigene“) · RECHTE (Anzahl, „n gefährlich“) · ZUWEISUNGEN · AKTIVE BENUTZER; „Rolle anlegen“ (nur `roles.manage`). Darunter die vorhandene Rechte-Matrix (Spalten = alle Rollen). Ohne `roles.manage` nur lesend wie bisher |
| `#/benutzer/rollen/neu` · `#/benutzer/rollen/{id}` | **Rolle bearbeiten** | Karte „Stammdaten“ (Name, Beschreibung; Standardrolle: Name als Text + Badge „Standardrolle“). Karte „Rechte“: Gruppen „Jobs“ (ansehen, ausführen, HTTP bearbeiten, Statusseiten) und „Gefährlich – nur für alle Kategorien“ (rot markiert, `badge--danger`), je Recht ein Kontrollkästchen mit Bezeichnung und Erklärsatz; Rechte, die man selbst nicht hat → deaktiviert mit Hinweis „Du hast dieses Recht selbst nicht.“; Admin: alles angehakt und deaktiviert („Die Rolle Admin hat immer alle Rechte.“). Karte „Auswirkung“: „Gilt sofort für n Benutzer, auch in offenen Sitzungen.“; bei neuem gefährlichem Recht und beschränkten Zuweisungen `Alert` mit Anzahl und Link „Benutzer mit dieser Rolle“ (`#/benutzer?rolle=5`). Karte „Rolle löschen“ (nur eigene; deaktiviert bei Zuweisungen > 0 mit demselben Link). Knopf „Duplizieren“ öffnet „neu“ mit denselben Rechten (nur im Zustand) |
| Speichern | `Confirm` mit Änderungsliste („+ Jobs ausführen, − HTTP-Jobs bearbeiten“) und Folgen; `PasswordConfirm`, wenn ein gefährliches Recht dazukommt oder wegfällt |
| **Selbstaussperrung** | Ist die Rolle dem Handelnden zugewiesen (`held_by_me`): Karte „Rechte“ und „Löschen“ deaktiviert, Hinweis „Diese Rolle ist dir selbst zugewiesen. Rechte ändert ein anderer Administrator.“ Entfernt eine Änderung `users.manage`/`roles.manage`, nennt der `Confirm` „n Benutzer verlieren den Zugang zur Benutzer- bzw. Rollenverwaltung.“ |
| `#/benutzer` | Filter „Rolle“ (Auswahl), ROLLEN-Spalte unverändert | `GET /api/users?role_id=` |
| `AssignmentEditor` | Höchstzahl aus `max_assignments` statt `3`; Beschreibung der Rolle unter der Auswahl; gefährliche Rolle erzwingt „Alle Kategorien“ (vorhanden) | |

Feldfehler aus 422 am Feld (`aria-describedby`), 409 als `Alert` über dem Formular, Tabellen auf Handybreite im
Container scrollbar, Kontrollkästchen mit sichtbarer Beschriftung (keine reinen Haken-Zellen zum Klicken).

---

## 8. Scheiben (Reihenfolge R1 → R6; jede mit grünem `composer check` bzw. `npm run build` + Typprüfung und eigenem Commit)

| # | Agent | Inhalt | Dateien | Abnahme / Tests |
|---|---|---|---|---|
| R1 | sicherheit | Migration 0013 (§3), `Permission::ManageRoles` (gefährlich, `match`), `BuiltinRole`, `Role` erweitert, `RoleCatalog` (`builtin_key`, `description`, `findByName`, `builtin()`), `GrantPolicy::mayDefineRole/requireRoleManager`, `admin:bootstrap`/`user:create` über Kennung bzw. Name NOCASE, `migrate`-Warnung bei 0 Admins und Abbruch bei fehlender Kennung | `migrations/0013_custom_roles.sql`, `src/Security/Permission.php`, `src/Security/GrantPolicy.php`, `src/User/{BuiltinRole,Role,RoleCatalog,UserRepository}.php`, `src/Console/{AdminBootstrapCommand,UserCreateCommand,MigrateCommand}.php` | Bestand: `grantsFor()` aller Benutzer vor/nach gleich bis auf `+roles.manage` bei Admin, `countAdmins()` gleich; zweimal einspielen ändert nichts; Trigger: Standardrolle löschen/umbenennen/Kennung ändern, eigene Rolle befördern, Admin-Recht löschen, `role_permissions` umschreiben, unbekanntes Recht → je Abbruch; `roles_name_nocase` lehnt `admin` ab; Trigger-Liste ≡ `Permission::cases()`; Eigenschaftstest `mayDefineRole` gegen `can()` über alle Rechte × {alle, A, ohne}; kein statischer Rechte-Cache (Test/grep) |
| R2 | sicherheit | `RoleName`, `RoleValidator`, `RoleRepository`, `RoleAdminService` (E3–E5, E7, Grenzen), `UserAdminService`: E3 in der Transaktion, `MAX_ASSIGNMENTS = 10` | `src/User/{RoleName,RoleValidator,RoleRepository,RoleAdminService,RoleUsage,RoleRequestRefused,UserAdminService,AssignmentValidator}.php` | Admin-Rolle Rechte setzen/löschen → 409; eigene Rolle → 409, Umbenennen ok; Recht, das der Handelnde nicht hat (hinzu **und** weg) → 403; Inhaber mit mehr Rechten → 403; gefährlich + beschränkte Zuweisung → 409 mit Anzahl; letzte Admin-Quelle (eigene Rolle mit allen Rechten) entziehen → 409 + Rollback; Passwort nur bei gefährlicher Änderung (fehlt 422, falsch 403 + `auth.reauth_failed`, gesperrt 429); Wettlauf zwei Prozesse (Rolle wird gefährlich ↔ beschränkte Zuweisung anlegen) → genau einer scheitert; Herabstufung des Handelnden zwischen Prüfen und Schreiben → nichts geschrieben; Audit-Texte §E7; 61. Änderung/Stunde → 429; 51. Rolle → 409; Roundtrip über neue `Connection` |
| R3 | backend | `Http\RoleController` (§5), `RolePresenter` bzw. `UserPresenter::roles()` erweitert, `GET /api/users?role_id=`, `/me` maskiert Rollennamen, Routen im `AppFactory` | `src/Http/{RoleController,UserPresenter,UserController,AuthController,AppFactory}.php` | Rollen-Matrix §5 (Admin, Admin/A, Op, Op/A, Beob, Rollenpfleger ±`users.manage`, anonym); CSRF fehlt/falsch/fremder Origin → 403 `csrf_failed` ohne Änderung (Eintrag in `RoleMatrixTest` über alle ändernden Routen); 403 vor 404/422 ohne `roles.manage`; Feld-Allowlist und Reihenfolge; Leak: Test-Geheimnis als Name/Beschreibung in keiner Antwort, Audit, `/me` unmaskiert; `current_password` nie in Antwort/Audit/`error_log`/Trace; strenge Typen (`"5"` ist keine ID, `permissions: "jobs.view"` → 422) |
| R4 | tester | Wirkung sofort (E6) und Gesamtsuite | `tests/Integration/Phase5/{RoleApiTest,RoleLiveEffectTest,RolePrivilegeTest,RoleLeakTest}.php` | Offene Sitzung: Recht an Rolle entzogen → nächste Anfrage 403/404, Liste gekappt; SSE endet `forbidden` ≤ 15 s; wartender manueller Lauf → `skipped`; Rolle gelöscht → Zuweisung weg, nie „alle“; neues Recht per Migration erreicht nur Admin; Mutation: `mayDefineRole`, Inhaber-`mayManage`, Selbstbezug, `assertHeld()` oder E3 entfernen → Test fällt |
| R5 | frontend | Screens §7 (Rollenliste, Rollen-Editor, Duplizieren, Benutzer-Filter, `AssignmentEditor`-Grenze, Menü, `visibilitychange` → `/me`) | `frontend/src/screens/{Users,RoleEdit}.tsx`, `frontend/src/components/AssignmentEditor.tsx`, `frontend/src/lib/permissions.ts`, `frontend/src/types.ts`, `frontend/src/App.tsx`, `frontend/src/components/Layout.tsx` | Ohne `roles.manage` kein „Rolle anlegen“, keine Kontrollkästchen, keine Aufrufe von `/api/roles/manage`; Admin-Rolle und eigene Rolle deaktiviert mit Hinweis; gefährliche Rechte rot markiert; Passwortfeld nur bei gefährlicher Änderung; 409/422 als `Alert`/am Feld; kein `dangerouslySetInnerHTML`, nichts im Storage; Handybreite; Build ohne CSP-Fehler |
| R6 | sicherheit | Review nach `mer-security` §15 (Teil des Reviews Phase 5) und Regeländerungen §10 eintragen lassen | – | alle Befunde behoben oder von Alex akzeptiert |

Reihenfolge: R1 → R2 → R3 → (R4 ∥ R5) → R6. Aufwand gesamt etwa 2,5–3 PT (wie in 0005 E1 geschätzt).

---

## 9. Offene Entscheidungen für Alex

Bis zur Entscheidung gilt die Empfehlung (fett).

| # | Frage | Optionen | Empfehlung |
|---|---|---|---|
| F1 | Standardrollen Operator und Beobachter | Rechte änderbar, Name fest · ganz gesperrt (nur kopieren) · alles änderbar | **Rechte änderbar, Name fest, nicht löschbar** |
| F2 | Admin-Rolle | ganz fest · Rechte änderbar | **Ganz fest (immer alle Rechte)** – Anker für Notausgang, Invariante und neue Rechte |
| F3 | Recht für Rollenpflege | neues `roles.manage` (gefährlich) · `users.manage` mitbenutzen | **Neues `roles.manage`**, per Migration nur an Admin |
| F4 | Rolle mit Zuweisungen löschen | ablehnen (409) · „Umhängen auf Rolle X“ in einem Schritt | **Ablehnen**, Link auf gefilterte Benutzerliste; Umhängen später |
| F5 | Eigene Rolle bearbeiten | Rechte/Löschen verboten, Name erlaubt · ganz verboten · erlaubt mit Warnung | **Rechte und Löschen verboten, Name/Beschreibung erlaubt** |
| F6 | Passwort-Bestätigung | bei gefährlichem Recht dazu/weg · bei jeder Rechteänderung · nie | **Nur bei gefährlichem Recht dazu/weg** (wie 0005 E7) |
| F7 | Grenzen | Zuweisungen je Benutzer 3 → 10, Rollen ≤ 50 · andere Werte | **10 Zuweisungen, 50 Rollen, 60 Änderungen je Stunde** |
| F8 | Rollenänderung, wenn ein Inhaber mehr Rechte hat als der Handelnde | ablehnen · erlauben | **Ablehnen (403)** – sonst Umweg um „niemand verwaltet jemanden mit mehr Rechten“ |
| F9 | Beschreibung je Rolle | ja (optional, ≤ 200) · nein | **Ja** |

---

## 10. Regeländerungen für `CLAUDE.md`, Skills und Hook

Vom Koordinator einzutragen, spätestens mit R1 (Pflicht „Skill mitpflegen“).

- **`CLAUDE.md`, Sicherheitsnetz:** „Rollen sind Zuordnungen Rolle → Rechte aus dem festen Katalog `Permission`. Rechte
  einer Rolle setzt nur, wer jedes betroffene Recht (alt und neu) selbst uneingeschränkt hat, nie an einer eigenen Rolle,
  nie an der Admin-Rolle; `AdminInvariant` auch nach Rollenänderungen. Standardrollen nur über `builtin_key`
  ansprechen.“ (`mer-security`, `mer-storage`)
- **`mer-security` §4:** `roles.manage` gefährlich; `GrantPolicy::mayDefineRole`; Inhaber-`mayManage`; Selbstbezug 409;
  E3 (gefährlich nur bei ausschließlich „alle“-Zuweisungen, auch beim Rechte setzen); Passwort bei gefährlicher
  Rollenänderung; §14 Audit `role.*`; Verboten: „Rolle über ihren Namen identifizieren (Code, CLI, Migration)“,
  „Rechte einer Rolle ändern, ohne `assertHeld()` in derselben Transaktion“, „Rollenname unmaskiert ausgeben“.
- **`mer-storage` §3:** Satz „Rollen sind im MVP nicht über API/Oberfläche änderbar“ ersetzen durch: Standardrollen
  über `roles.builtin_key`, Admin-Rechte nie entfernen, neue Rechte per Migration `WHERE builtin_key = 'admin'` und
  Trigger `role_permissions_known` neu anlegen; Schreiben nur über `RoleRepository` aus `RoleAdminService`.
- **`mer-ui` §5:** Rechte-Matrix nicht mehr nur lesend; Rollen-Editor nach §7.
- **Hook:** Muster `name\s*=\s*'(Admin|Operator|Beobachter)'` in neuen Dateien unter `migrations/` (ab 0013) und in
  `src/` als Hinweis („Standardrolle über `builtin_key` ansprechen“).
