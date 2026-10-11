<?php

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\Row;
use Meridian\Security\Sealed;
use Meridian\Security\SecretBox;

/**
 * Kanäle (Tabelle `notification_channels`, ADR 0007 N1/N2). Passwort und Token nur verschlüsselt in `secret_enc`; gelesen
 * werden sie nur vom Zusteller über {@see self::secret()}, nie von der Verwaltungs-API. Schreiben und Audit-Eintrag in einer Transaktion.
 */
final class ChannelStore
{
    public const MAX_CHANNELS = 20;

    private const SELECT = 'SELECT id, name, type, config_json, secret_enc IS NOT NULL AS has_secret, is_enabled, created_at, updated_at FROM notification_channels';

    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<ChannelRecord>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->fetchAll(self::SELECT . ' ORDER BY name COLLATE NOCASE, id') as $row) {
            $out[] = self::record($row);
        }

        return $out;
    }

    public function find(int $id): ?ChannelRecord
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::record($row);
    }

    /**
     * Geheimnis eines Kanals (versiegelt) oder null, wenn keins gespeichert ist. Nur für den Zusteller.
     *
     * @return Sealed<string>|null
     */
    public function secret(int $id): ?Sealed
    {
        $row = $this->db->fetchOne('SELECT secret_enc FROM notification_channels WHERE id = :id', ['id' => $id]);
        $encrypted = $row === null ? null : Row::stringOrNull($row, 'secret_enc');

        return $encrypted === null ? null : new Sealed($this->box->decrypt($encrypted));
    }

    /**
     * @throws InvalidChannel Name vergeben oder Grenze erreicht
     */
    public function add(#[\SensitiveParameter] ChannelDraft $draft, int $userId, AuditLog $audit): ChannelRecord
    {
        return $this->db->immediate(function () use ($draft, $userId, $audit): ChannelRecord {
            $count = Row::int($this->db->fetchOne('SELECT COUNT(*) AS n FROM notification_channels') ?? ['n' => 0], 'n');
            if ($count >= self::MAX_CHANNELS) {
                throw new InvalidChannel('name', 'Höchstens ' . self::MAX_CHANNELS . ' Kanäle. Nicht benötigte Kanäle zuerst löschen.');
            }
            $this->assertNameFree($draft->name, null);
            $now = Timestamp::format($this->clock->now());
            $this->db->execute(
                'INSERT INTO notification_channels (name, type, config_json, secret_enc, is_enabled, created_at, updated_at)
                 VALUES (:name, :type, :config, :secret, :enabled, :now, :now)',
                [
                    'name' => $draft->name,
                    'type' => $draft->type->value,
                    'config' => json_encode($draft->config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'secret' => $draft->secret === null || $draft->secret === '' ? null : $this->box->encrypt($draft->secret),
                    'enabled' => $draft->isEnabled ? 1 : 0,
                    'now' => $now,
                ],
            );
            $id = $this->db->lastInsertId();
            $audit->record($userId, 'notification.channel_added', 'channel:' . $id . ' ' . $draft->name);

            return $this->mustFind($id);
        });
    }

    /**
     * @throws InvalidChannel Name vergeben
     */
    public function update(int $id, #[\SensitiveParameter] ChannelDraft $draft, int $userId, AuditLog $audit): ?ChannelRecord
    {
        return $this->db->immediate(function () use ($id, $draft, $userId, $audit): ?ChannelRecord {
            $existing = $this->find($id);
            if ($existing === null) {
                return null;
            }
            $this->assertNameFree($draft->name, $id);
            $this->db->execute(
                'UPDATE notification_channels SET name = :name, config_json = :config, is_enabled = :enabled, updated_at = :now,
                        secret_enc = CASE WHEN :change = 0 THEN secret_enc WHEN :secret IS NULL THEN NULL ELSE :secret END
                  WHERE id = :id',
                [
                    'name' => $draft->name,
                    'config' => json_encode($draft->config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'enabled' => $draft->isEnabled ? 1 : 0,
                    'now' => Timestamp::format($this->clock->now()),
                    'change' => $draft->secret === null ? 0 : 1,
                    'secret' => $draft->secret === null || $draft->secret === '' ? null : $this->box->encrypt($draft->secret),
                    'id' => $id,
                ],
            );
            // Nur Feldnamen ins Audit, nie Werte.
            $changed = [];
            foreach (['host', 'port', 'encryption', 'username', 'from', 'from_name', 'to', 'server', 'topic', 'priority'] as $key) {
                if (($existing->config[$key] ?? null) !== ($draft->config[$key] ?? null)) {
                    $changed[] = $key;
                }
            }
            if ($existing->name !== $draft->name) {
                $changed[] = 'name';
            }
            if ($existing->isEnabled !== $draft->isEnabled) {
                $changed[] = 'enabled';
            }
            if ($draft->secret !== null) {
                $changed[] = $draft->secret === '' ? 'secret_removed' : 'secret_replaced';
            }
            $audit->record($userId, 'notification.channel_updated', 'channel:' . $id . ' ' . $draft->name . ($changed === [] ? '' : '; geändert: ' . implode(', ', $changed)));

            return $this->mustFind($id);
        });
    }

    public function remove(int $id, int $userId, AuditLog $audit): bool
    {
        return $this->db->immediate(function () use ($id, $userId, $audit): bool {
            $existing = $this->find($id);
            if ($existing === null) {
                return false;
            }
            $this->db->execute('DELETE FROM notification_channels WHERE id = :id', ['id' => $id]);
            $audit->record($userId, 'notification.channel_removed', 'channel:' . $id . ' ' . $existing->name);

            return true;
        });
    }

    private function assertNameFree(string $name, ?int $exceptId): void
    {
        $row = $this->db->fetchOne('SELECT id FROM notification_channels WHERE name = :name AND (:id IS NULL OR id <> :id)', ['name' => $name, 'id' => $exceptId]);
        if ($row !== null) {
            throw new InvalidChannel('name', 'Es gibt schon einen Kanal mit diesem Namen (Groß-/Kleinschreibung zählt nicht).');
        }
    }

    private function mustFind(int $id): ChannelRecord
    {
        return $this->find($id) ?? throw new \LogicException('Kanal nach dem Schreiben nicht lesbar.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function record(array $row): ChannelRecord
    {
        /** @var mixed $decoded */
        $decoded = json_decode(Row::string($row, 'config_json'), true);
        /** @var array<string, mixed> $config */
        $config = is_array($decoded) ? $decoded : [];

        return new ChannelRecord(
            Row::int($row, 'id'),
            Row::string($row, 'name'),
            ChannelType::from(Row::string($row, 'type')),
            $config,
            Row::bool($row, 'has_secret'),
            Row::bool($row, 'is_enabled'),
            Row::string($row, 'created_at'),
            Row::string($row, 'updated_at'),
        );
    }
}
