<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Wo ein Docker-Lauf läuft (`runs.exec_ref`, docs/decisions/0004 E5/§5.5): Container, Exec-ID, Prozessgruppe,
 * Benutzer. Kein Geheimnis, aber **nie** in Antworten oder Ausgaben (H2). Dient dem Aufräumen nach einem Absturz;
 * die Exec-ID verhindert, dass eine wiederverwendete PID getroffen wird.
 */
final readonly class ExecRef
{
    private const EXEC_ID = '/^[0-9a-f]{64}$/D';

    public function __construct(
        public string $container,
        public string $execId,
        public ?int $pgid,
        public ?string $user,
        public bool $groupKill = true,
    ) {
        if (!ShellTargetKind::Docker->isValidName($container) || preg_match(self::EXEC_ID, $execId) !== 1
            || ($pgid !== null && ($pgid < 2 || $pgid > 4194304)) || ($user !== null && !ShellRules::isValidUser($user))) {
            throw new \InvalidArgumentException('Ungültige Ausführungsreferenz.');
        }
    }

    public static function isValidExecId(string $id): bool
    {
        return preg_match(self::EXEC_ID, $id) === 1;
    }

    public function withPgid(int $pgid, bool $groupKill): self
    {
        return new self($this->container, $this->execId, $pgid, $this->user, $groupKill);
    }

    public function toJson(): string
    {
        return json_encode(
            ['kind' => 'docker', 'container' => $this->container, 'exec_id' => $this->execId, 'pgid' => $this->pgid, 'user' => $this->user, 'group' => $this->groupKill],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /** Streng: alles Unbekannte oder Ungültige → null (wird dann nur gelöscht, nie benutzt). */
    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || ($data['kind'] ?? null) !== 'docker') {
            return null;
        }
        $container = $data['container'] ?? null;
        $execId = $data['exec_id'] ?? null;
        $pgid = $data['pgid'] ?? null;
        $user = $data['user'] ?? null;
        $group = $data['group'] ?? true;
        if (!is_string($container) || !is_string($execId) || ($pgid !== null && !is_int($pgid)) || ($user !== null && !is_string($user)) || !is_bool($group)) {
            return null;
        }
        try {
            return new self($container, $execId, $pgid, $user, $group);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
