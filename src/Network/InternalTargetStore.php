<?php

declare(strict_types=1);

namespace Meridian\Network;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\BlockReason;
use Meridian\Runner\Http\InfrastructureTargets;
use Meridian\Runner\Http\InternalTarget;
use Meridian\Runner\Http\InvalidInternalTarget;
use Meridian\Runner\Http\IpNetwork;

/**
 * Pflege der Freigaben interner Ziele (Tabelle `http_internal_targets`, docs/decisions/0003 E5). Nur für die
 * Admin-API (`network.internal_targets`) und die Befehlszeile; der Runner liest selbst über
 * `DbInternalTargetSource` und schreibt nie.
 *
 * Jede neue Freigabe läuft durch `InternalTarget::create()` — dieselbe Prüfung, die beim Laden gilt: nie
 * freigebbare Netze und Namen werden abgelehnt, Netze nur in Normalform. Die Kategorie muss existieren.
 */
final class InternalTargetStore
{
    public const MAX_NOTE_LENGTH = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly InfrastructureTargets $infrastructure = new InfrastructureTargets(),
    ) {
    }

    /**
     * Prüft die Eingabe vollständig, bevor geschrieben wird.
     *
     * @throws InvalidTargetInput
     */
    public function validate(string $kind, string $value, int $port, ?int $categoryId, string $note): InternalTarget
    {
        if ($categoryId !== null && !$this->categoryExists($categoryId)) {
            throw new InvalidTargetInput('category_id', 'Kategorie unbekannt. Eine bestehende Kategorie wählen oder leer lassen (gilt dann global).');
        }
        if (mb_strlen($note, 'UTF-8') > self::MAX_NOTE_LENGTH || preg_match('//u', $note) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $note) === 1) {
            throw new InvalidTargetInput('note', 'Die Notiz darf höchstens 200 Zeichen lang sein und keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.');
        }
        if (strlen($value) > 253) {
            throw new InvalidTargetInput('value', 'Der Wert ist zu lang (höchstens 253 Zeichen).');
        }
        try {
            $target = InternalTarget::create($kind, $value, $port, $categoryId);
        } catch (InvalidInternalTarget $e) {
            throw new InvalidTargetInput($e->field, $e->getMessage());
        }
        // Netze nur in Normalform annehmen (ablehnen statt umschreiben), z. B. FD12::/16 → fd12::/16.
        if ($target->value !== $value) {
            throw new InvalidTargetInput('value', $kind === InternalTarget::KIND_HOST
                ? 'Hostnamen bitte in Kleinbuchstaben angeben.'
                : 'Das Netz bitte in Normalform angeben (IPv6 klein geschrieben und gekürzt, z. B. fd12:3456::/48).');
        }
        // Infrastruktur nie freigeben (zur Laufzeit sperrt AddressPolicy sie ohnehin; hier mit klarer Meldung).
        if ($target->kind === InternalTarget::KIND_HOST && $this->infrastructure->isDockerProxyHost($target->value)) {
            throw new InvalidTargetInput('value', 'Der docker-socket-proxy ist nie freigebbar (sonst würde ein HTTP-Job zum Shell-Job).');
        }
        if ($target->kind === InternalTarget::KIND_CIDR && $target->port === $this->infrastructure->listenPort
            && AddressPolicy::classify(IpNetwork::fromCidr($target->value)->address()) === BlockReason::Loopback) {
            throw new InvalidTargetInput('port', 'Meridians eigener Port (MERIDIAN_LISTEN_PORT) ist auf Loopback nie freigebbar. Einen anderen Port wählen.');
        }

        return $target;
    }

    /**
     * Legt die Freigabe an und schreibt den Audit-Eintrag `network.internal_target_added` in **derselben**
     * Transaktion: scheitert das Protokoll, gibt es auch keine Freigabe (Schutz lockern nur mit Audit).
     *
     * @throws InvalidTargetInput doppelte Freigabe
     */
    public function add(InternalTarget $target, string $note, ?int $userId, AuditLog $audit): InternalTargetRecord
    {
        return $this->db->immediate(function () use ($target, $note, $userId, $audit): InternalTargetRecord {
            $exists = $this->db->fetchOne(
                'SELECT id FROM http_internal_targets WHERE kind = :kind AND value = :value AND port = :port AND COALESCE(category_id, 0) = COALESCE(:category, 0)',
                ['kind' => $target->kind, 'value' => $target->value, 'port' => $target->port, 'category' => $target->categoryId],
            );
            if ($exists !== null) {
                throw new InvalidTargetInput('value', 'Diese Freigabe gibt es schon (gleiche Art, gleicher Wert, gleicher Port, gleicher Geltungsbereich).');
            }
            $this->db->execute(
                'INSERT INTO http_internal_targets (kind, value, port, category_id, note, created_by, created_at) VALUES (:kind, :value, :port, :category, :note, :user, :at)',
                [
                    'kind' => $target->kind,
                    'value' => $target->value,
                    'port' => $target->port,
                    'category' => $target->categoryId,
                    'note' => $note,
                    'user' => $userId,
                    'at' => Timestamp::format($this->clock->now()),
                ],
            );
            $record = $this->find($this->db->lastInsertId());
            if ($record === null) {
                throw new \RuntimeException('Die Freigabe wurde gespeichert, ist aber nicht lesbar.');
            }
            $audit->record($userId, 'network.internal_target_added', $record->describe());

            return $record;
        });
    }

    /**
     * Löscht eine Freigabe und schreibt `network.internal_target_removed` in derselben Transaktion; gibt sie zurück,
     * null, wenn es sie nicht gibt (dann kein Audit-Eintrag).
     */
    public function remove(int $id, ?int $userId, AuditLog $audit): ?InternalTargetRecord
    {
        return $this->db->immediate(function () use ($id, $userId, $audit): ?InternalTargetRecord {
            $record = $this->find($id);
            if ($record === null) {
                return null;
            }
            $this->db->execute('DELETE FROM http_internal_targets WHERE id = :id', ['id' => $id]);
            $audit->record($userId, 'network.internal_target_removed', $record->describe());

            return $record;
        });
    }

    /**
     * @return list<InternalTargetRecord>
     */
    public function all(): array
    {
        $records = [];
        foreach ($this->db->fetchAll(
            'SELECT t.id, t.kind, t.value, t.port, t.category_id, c.name AS category_name, t.note, t.created_at, u.display_name AS created_by
               FROM http_internal_targets t
               LEFT JOIN categories c ON c.id = t.category_id
               LEFT JOIN users u ON u.id = t.created_by
              ORDER BY t.id',
        ) as $row) {
            $record = self::record($row);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function find(int $id): ?InternalTargetRecord
    {
        return self::record($this->db->fetchOne(
            'SELECT t.id, t.kind, t.value, t.port, t.category_id, c.name AS category_name, t.note, t.created_at, u.display_name AS created_by
               FROM http_internal_targets t
               LEFT JOIN categories c ON c.id = t.category_id
               LEFT JOIN users u ON u.id = t.created_by
              WHERE t.id = :id',
            ['id' => $id],
        ));
    }

    public function categoryIdByName(string $name): ?int
    {
        $row = $this->db->fetchOne('SELECT id FROM categories WHERE name = :name', ['name' => $name]);

        return isset($row['id']) && is_int($row['id']) ? $row['id'] : null;
    }

    private function categoryExists(int $id): bool
    {
        return $this->db->fetchOne('SELECT 1 AS found FROM categories WHERE id = :id', ['id' => $id]) !== null;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private static function record(?array $row): ?InternalTargetRecord
    {
        if ($row === null) {
            return null;
        }
        $id = $row['id'] ?? null;
        $kind = $row['kind'] ?? null;
        $value = $row['value'] ?? null;
        $port = $row['port'] ?? null;
        $category = $row['category_id'] ?? null;
        $categoryName = $row['category_name'] ?? null;
        $note = $row['note'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        $createdBy = $row['created_by'] ?? null;
        if (!is_int($id) || ($kind !== 'cidr' && $kind !== 'host') || !is_string($value) || !is_int($port)
            || ($category !== null && !is_int($category)) || ($categoryName !== null && !is_string($categoryName))
            || !is_string($note) || !is_string($createdAt) || ($createdBy !== null && !is_string($createdBy))) {
            return null;
        }

        return new InternalTargetRecord($id, $kind, $value, $port, $category, $categoryName, $note, $createdAt, $createdBy);
    }
}
