<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Allowlist der Host-Profile und ihrer Agent-Sockets (`MERIDIAN_SHELL_HOST_SOCKETS`, z. B.
 * `default=/run/meridian-shell/default.sock,wartung=/run/meridian-shell/wartung.sock`). Der Name ist ein Host-Profil wie in der
 * Freigabe (`host default`); der Pfad ein absoluter Socket-Pfad auf `.sock` ohne `..`, nie unter Meridians Daten- oder
 * Schlüsselverzeichnis. Eine ungültige Angabe verhindert den Start (nichts wird zurechtgeschnitten).
 */
final readonly class HostSockets
{
    private const PATH = '#^/[A-Za-z0-9._/-]{1,200}\.sock$#D';
    private const FORBIDDEN_PREFIXES = ['/etc/meridian', '/var/lib/meridian'];
    public const MAX_PROFILES = 8;

    /**
     * @param array<string, string> $sockets Profilname => Pfad
     */
    private function __construct(private array $sockets)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function parse(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            return self::none();
        }
        $sockets = [];
        foreach (explode(',', $value) as $entry) {
            $parts = explode('=', trim($entry), 2);
            $name = $parts[0];
            $path = $parts[1] ?? '';
            if (count($parts) !== 2 || !ShellTargetKind::Host->isValidName($name) || isset($sockets[$name]) || self::badPath($path)) {
                throw new \InvalidArgumentException('MERIDIAN_SHELL_HOST_SOCKETS: kommagetrennte Einträge Profil=/pfad/zum/agent.sock (Profil in Kleinbuchstaben, Pfad auf .sock ohne „..“, nicht unter /etc/meridian oder /var/lib/meridian).');
            }
            $sockets[$name] = $path;
        }
        if (count($sockets) > self::MAX_PROFILES) {
            throw new \InvalidArgumentException('MERIDIAN_SHELL_HOST_SOCKETS: höchstens ' . self::MAX_PROFILES . ' Profile.');
        }

        return new self($sockets);
    }

    public function pathFor(string $profile): ?string
    {
        return $this->sockets[$profile] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->sockets === [];
    }

    private static function badPath(string $path): bool
    {
        if (preg_match(self::PATH, $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
            return true;
        }
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
