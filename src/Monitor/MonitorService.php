<?php

declare(strict_types=1);

namespace Meridian\Monitor;

use Meridian\Auth\AuditLog;
use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Http\ValidationFailed;
use Meridian\Security\AccessControl;
use Meridian\Security\AccessDenied;
use Meridian\Security\Permission;
use Meridian\Security\PingToken;
use Meridian\Security\RoleGrant;

/**
 * Überwachungen anlegen, ändern, löschen, Token erneuern (ADR 0011). Recht: `jobs.edit_http` in der Kategorie der
 * Überwachung (beim Ändern in der alten **und** der neuen), sichtbar muss sie über `jobs.view` sein. Jede Aktion samt
 * Audit in einer Transaktion; das Audit nennt Namen und Feldnamen, nie Token oder Hash.
 */
final class MonitorService
{
    public const MAX_MONITORS = 200;
    private const EDIT = Permission::EditHttpJobs;

    public function __construct(
        private readonly Connection $db,
        private readonly MonitorRepository $monitors,
        private readonly MonitorValidator $validator,
        private readonly AccessControl $access,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<RoleGrant> $grants
     * @param array<mixed>    $input
     *
     * @return array{record: MonitorRecord, plain: string}
     *
     * @throws AccessDenied|ValidationFailed
     */
    public function create(int $userId, array $grants, array $input): array
    {
        $editScope = $this->access->scope($grants, self::EDIT);
        if ($editScope->isEmpty()) {
            throw new AccessDenied(self::EDIT);
        }

        return $this->db->immediate(function () use ($userId, $grants, $input, $editScope): array {
            $draft = $this->validator->validate($input, $editScope, null);
            $this->access->require($grants, self::EDIT, $draft->categoryName);
            if (!array_key_exists('interval_seconds', $input)) {
                throw ValidationFailed::field('interval_seconds', 'Pflichtfeld: erwartetes Intervall in Sekunden.');
            }
            $count = (int) ($this->db->fetchOne('SELECT COUNT(*) AS n FROM monitors')['n'] ?? 0);
            if ($count >= self::MAX_MONITORS) {
                throw ValidationFailed::field('name', 'Höchstens ' . self::MAX_MONITORS . ' Überwachungen. Nicht benötigte zuerst löschen.');
            }
            if ($this->monitors->nameTaken($draft->name, null)) {
                throw ValidationFailed::field('name', 'Es gibt schon eine Überwachung mit diesem Namen.');
            }
            $token = PingToken::issue();
            $id = $this->monitors->insert($draft, $userId, $token['hash'], Timestamp::format($this->clock->now()));
            $this->audit->record($userId, 'monitor.created', 'monitor:' . $id . ' ' . $draft->name);
            $record = $this->monitors->findVisible($id, $editScope) ?? throw new \LogicException('Überwachung fehlt nach dem Anlegen.');

            return ['record' => $record, 'plain' => $token['plain']];
        });
    }

    /**
     * @param list<RoleGrant> $grants
     * @param array<mixed>    $input
     *
     * @return MonitorRecord|null null = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied|ValidationFailed
     */
    public function update(int $userId, array $grants, int $id, array $input): ?MonitorRecord
    {
        $viewScope = $this->access->scope($grants, Permission::ViewJobs);

        return $this->db->immediate(function () use ($userId, $grants, $id, $input, $viewScope): ?MonitorRecord {
            $existing = $this->monitors->findVisible($id, $viewScope);
            if ($existing === null) {
                return null;
            }
            $this->access->require($grants, self::EDIT, $existing->categoryName);
            $draft = $this->validator->validate($input, $this->access->scope($grants, self::EDIT), $existing);
            $this->access->require($grants, self::EDIT, $draft->categoryName);
            if ($this->monitors->nameTaken($draft->name, $id)) {
                throw ValidationFailed::field('name', 'Es gibt schon eine Überwachung mit diesem Namen.');
            }
            $rearm = ($draft->isEnabled && !$existing->isEnabled)
                || $draft->intervalSeconds !== $existing->intervalSeconds
                || $draft->graceSeconds !== $existing->graceSeconds;
            $this->monitors->update($id, $draft, $rearm, Timestamp::format($this->clock->now()));
            $changed = [];
            foreach ([
                'name' => $draft->name !== $existing->name,
                'category_id' => $draft->categoryId !== $existing->categoryId,
                'interval_seconds' => $draft->intervalSeconds !== $existing->intervalSeconds,
                'grace_seconds' => $draft->graceSeconds !== $existing->graceSeconds,
                'is_enabled' => $draft->isEnabled !== $existing->isEnabled,
                'notify_on_down' => $draft->notifyOnDown !== $existing->notifyOnDown,
                'notify_on_up' => $draft->notifyOnUp !== $existing->notifyOnUp,
                'notify_channels' => $draft->notifyChannels !== $existing->notifyChannels,
            ] as $field => $differs) {
                if ($differs) {
                    $changed[] = $field;
                }
            }
            if ($changed !== []) {
                $this->audit->record($userId, 'monitor.updated', 'monitor:' . $id . ' ' . $draft->name . '; geändert: ' . implode(', ', $changed));
            }

            return $this->monitors->findVisible($id, $viewScope);
        });
    }

    /**
     * @param list<RoleGrant> $grants
     *
     * @return bool false = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied
     */
    public function delete(int $userId, array $grants, int $id): bool
    {
        $viewScope = $this->access->scope($grants, Permission::ViewJobs);

        return $this->db->immediate(function () use ($userId, $grants, $id, $viewScope): bool {
            $existing = $this->monitors->findVisible($id, $viewScope);
            if ($existing === null) {
                return false;
            }
            $this->access->require($grants, self::EDIT, $existing->categoryName);
            $this->monitors->delete($id);
            $this->audit->record($userId, 'monitor.deleted', 'monitor:' . $id . ' ' . $existing->name);

            return true;
        });
    }

    /**
     * Erneuert das Token; das alte gilt sofort nicht mehr. Der Klartext wird nur einmal zurückgegeben.
     *
     * @param list<RoleGrant> $grants
     *
     * @return string|null null = nicht sichtbar (→ 404)
     *
     * @throws AccessDenied
     */
    public function regenerateToken(int $userId, array $grants, int $id): ?string
    {
        $viewScope = $this->access->scope($grants, Permission::ViewJobs);

        return $this->db->immediate(function () use ($userId, $grants, $id, $viewScope): ?string {
            $existing = $this->monitors->findVisible($id, $viewScope);
            if ($existing === null) {
                return null;
            }
            $this->access->require($grants, self::EDIT, $existing->categoryName);
            $token = PingToken::issue();
            $this->monitors->replaceToken($id, $token['hash'], Timestamp::format($this->clock->now()));
            $this->audit->record($userId, 'monitor.token_renewed', 'monitor:' . $id . ' ' . $existing->name);

            return $token['plain'];
        });
    }
}
