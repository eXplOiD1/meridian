<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Http\ValidationFailed;
use Meridian\Job\Row;
use Meridian\Security\ApiToken;

/**
 * Zugriffstoken für die REST-API (ADR 0009): anlegen (Klartext genau einmal), auflisten, widerrufen, prüfen.
 * Gespeichert wird nur der SHA-256-Hash eines Tokens mit 256 Bit Zufall (Raten ist aussichtslos, ein Sperrzähler wäre unnötig).
 * Geprüft wird bei jeder Anfrage: Hash bekannt, nicht abgelaufen, Benutzer aktiv, nicht gelöscht, kein offener Passwortwechsel.
 */
final class ApiTokenService
{
    public const MAX_PER_USER = 20;
    public const MAX_EXPIRY_DAYS = 365;
    public const DEFAULT_EXPIRY_DAYS = 90;
    /** Die letzte Nutzung wird höchstens einmal je Minute geschrieben (keine Schreiblast je Anfrage). */
    private const TOUCH_SECONDS = 60;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @param int|null $expiresInDays null = läuft nie ab
     *
     * @return array{record: ApiTokenRecord, plain: string}
     *
     * @throws ValidationFailed
     */
    public function create(int $userId, string $name, ?int $expiresInDays, bool $readOnly, AuditLog $audit): array
    {
        $errors = [];
        if (preg_match('/^[^\x00-\x1F\x7F]{1,60}$/Du', $name) !== 1) {
            $errors['name'] = 'Name: 1 bis 60 Zeichen ohne Steuerzeichen.';
        }
        if ($expiresInDays !== null && ($expiresInDays < 1 || $expiresInDays > self::MAX_EXPIRY_DAYS)) {
            $errors['expires_in_days'] = 'Gültigkeit: 1 bis ' . self::MAX_EXPIRY_DAYS . ' Tage oder leer (läuft nie ab).';
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $this->db->immediate(function () use ($userId, $name, $expiresInDays, $readOnly, $audit): array {
            $count = Row::int($this->db->fetchOne('SELECT COUNT(*) AS n FROM api_tokens WHERE user_id = :u', ['u' => $userId]) ?? ['n' => 0], 'n');
            if ($count >= self::MAX_PER_USER) {
                throw ValidationFailed::field('name', 'Höchstens ' . self::MAX_PER_USER . ' Token je Benutzer. Nicht benötigte zuerst widerrufen.');
            }
            $now = $this->clock->now();
            $issued = ApiToken::issue();
            $expires = $expiresInDays === null ? null : Timestamp::format($now->modify('+' . $expiresInDays . ' days'));
            $this->db->execute(
                'INSERT INTO api_tokens (user_id, name, token_hash, expires_at, created_at, read_only) VALUES (:u, :name, :hash, :exp, :now, :ro)',
                ['u' => $userId, 'name' => $name, 'hash' => $issued['hash'], 'exp' => $expires, 'now' => Timestamp::format($now), 'ro' => $readOnly ? 1 : 0],
            );
            $id = $this->db->lastInsertId();
            $audit->record($userId, 'token.created', 'token:' . $id . ' ' . $name . ($readOnly ? ' (nur lesen)' : ' (lesen und schreiben)'));

            return ['record' => new ApiTokenRecord($id, $name, $readOnly, $expires, null, Timestamp::format($now)), 'plain' => $issued['plain']];
        });
    }

    /**
     * @return list<ApiTokenRecord>
     */
    public function list(int $userId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, name, read_only, expires_at, last_used_at, created_at FROM api_tokens WHERE user_id = :u ORDER BY id DESC', ['u' => $userId]) as $row) {
            $out[] = new ApiTokenRecord(Row::int($row, 'id'), Row::string($row, 'name'), Row::bool($row, 'read_only'), Row::stringOrNull($row, 'expires_at'), Row::stringOrNull($row, 'last_used_at'), Row::string($row, 'created_at'));
        }

        return $out;
    }

    /** Widerruft ein eigenes Token; ein fremdes oder unbekanntes ergibt false (nie ein Hinweis, ob es existiert). */
    public function revoke(int $userId, int $id, AuditLog $audit): bool
    {
        return $this->db->immediate(function () use ($userId, $id, $audit): bool {
            $row = $this->db->fetchOne('SELECT name FROM api_tokens WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
            if ($row === null) {
                return false;
            }
            $this->db->execute('DELETE FROM api_tokens WHERE id = :id', ['id' => $id]);
            $audit->record($userId, 'token.revoked', 'token:' . $id . ' ' . Row::string($row, 'name'));

            return true;
        });
    }

    /**
     * Prüft ein Token aus dem Header. null = ungültig (unbekannt, abgelaufen, Benutzer gesperrt oder Pflichtwechsel offen).
     */
    public function resolve(#[\SensitiveParameter] string $plain): ?TokenGrant
    {
        if (strlen($plain) < 10 || strlen($plain) > 100 || !str_starts_with($plain, 'mrd_')) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT t.id, t.user_id, t.read_only, t.expires_at, t.last_used_at, t.token_hash FROM api_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = :h AND u.is_active = 1 AND u.deleted_at IS NULL AND u.password_must_change = 0',
            ['h' => ApiToken::hash($plain)],
        );
        if ($row === null || !ApiToken::matches($plain, Row::string($row, 'token_hash'))) {
            return null;
        }
        $now = $this->clock->now();
        $expires = Row::stringOrNull($row, 'expires_at');
        if ($expires !== null && $now >= Timestamp::parse($expires)) {
            return null;
        }
        $last = Row::stringOrNull($row, 'last_used_at');
        if ($last === null || $now->getTimestamp() - Timestamp::parse($last)->getTimestamp() >= self::TOUCH_SECONDS) {
            $this->db->execute('UPDATE api_tokens SET last_used_at = :now WHERE id = :id', ['now' => Timestamp::format($now), 'id' => Row::int($row, 'id')]);
        }

        return new TokenGrant(Row::int($row, 'user_id'), Row::int($row, 'id'), Row::bool($row, 'read_only'));
    }
}
