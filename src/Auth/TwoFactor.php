<?php

declare(strict_types=1);

namespace Meridian\Auth;

use Meridian\Database\Connection;
use Meridian\Security\PasswordHasher;
use Meridian\Security\SecretBox;

/**
 * Zwei-Faktor-Anmeldung mit TOTP und Wiederherstellungscodes.
 *
 * Das Secret liegt nur verschlüsselt in users.totp_secret_enc. Es erscheint genau einmal im Klartext:
 * bei der Einrichtung in der Antwort, damit der Benutzer es in seine App übernehmen kann.
 * Wiederherstellungscodes stehen nur als Argon2id-Hash in der Datenbank und werden einmal angezeigt.
 */
final class TwoFactor
{
    public const ISSUER = 'Meridian';
    private const RECOVERY_CODES = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretBox $box,
        private readonly PasswordHasher $hasher,
        private readonly Clock $clock,
    ) {
    }

    public function isEnabled(int $userId): bool
    {
        return $this->state($userId)['enabled'];
    }

    /**
     * Legt ein neues, noch unbestätigtes Secret an. Schon aktives 2FA wird nie überschrieben.
     *
     * @return array{secret: string, uri: string}|null null, wenn 2FA bereits aktiv ist
     */
    public function beginSetup(int $userId, string $account): ?array
    {
        if ($this->state($userId)['enabled']) {
            return null;
        }

        $secret = Totp::generateSecret();
        $this->db->execute(
            'UPDATE users SET totp_secret_enc = :s, totp_enabled = 0, totp_last_step = NULL WHERE id = :id',
            ['s' => $this->box->encrypt($secret), 'id' => $userId],
        );

        return ['secret' => $secret, 'uri' => Totp::uri($secret, self::ISSUER, $account)];
    }

    /**
     * Bestätigt die Einrichtung mit einem Code aus der App, schaltet 2FA scharf und erzeugt die
     * Wiederherstellungscodes.
     *
     * @return list<string>|null die Codes im Klartext (nur jetzt sichtbar), null bei falschem Code
     */
    public function confirm(int $userId, string $code): ?array
    {
        $state = $this->state($userId);
        if ($state['enabled'] || $state['secret'] === null) {
            return null;
        }

        $step = Totp::verify($state['secret'], trim($code), $this->clock->now(), null);
        if ($step === null) {
            return null;
        }

        $plain = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; ++$i) {
            $code = self::newRecoveryCode();
            $plain[] = self::formatRecoveryCode($code);
            $hashes[] = $this->hasher->hash($code);
        }

        $this->db->transaction(function (Connection $db) use ($userId, $step, $hashes): void {
            $db->execute('UPDATE users SET totp_enabled = 1, totp_last_step = :step WHERE id = :id', ['step' => $step, 'id' => $userId]);
            $db->execute('DELETE FROM recovery_codes WHERE user_id = :id', ['id' => $userId]);
            foreach ($hashes as $hash) {
                $db->execute('INSERT INTO recovery_codes (user_id, code_hash) VALUES (:u, :h)', ['u' => $userId, 'h' => $hash]);
            }
        });

        return $plain;
    }

    /**
     * Prüft den zweiten Faktor bei der Anmeldung: TOTP-Code oder Wiederherstellungscode.
     */
    public function verify(int $userId, string $input): ?TwoFactorMethod
    {
        $state = $this->state($userId);
        if (!$state['enabled'] || $state['secret'] === null) {
            return null;
        }

        $input = trim($input);
        if (preg_match('/^[0-9]{6}$/', $input) === 1) {
            return $this->verifyTotp($userId, $state['secret'], $state['lastStep'], $input) ? TwoFactorMethod::Totp : null;
        }

        return $this->verifyRecovery($userId, $input) ? TwoFactorMethod::Recovery : null;
    }

    /**
     * Schaltet 2FA ab: Secret, Zähler und alle Wiederherstellungscodes werden gelöscht.
     */
    public function disable(int $userId): void
    {
        $this->db->transaction(static function (Connection $db) use ($userId): void {
            $db->execute('UPDATE users SET totp_secret_enc = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = :id', ['id' => $userId]);
            $db->execute('DELETE FROM recovery_codes WHERE user_id = :id', ['id' => $userId]);
        });
    }

    private function verifyTotp(int $userId, #[\SensitiveParameter] string $secret, ?int $lastStep, string $code): bool
    {
        $step = Totp::verify($secret, $code, $this->clock->now(), $lastStep);
        if ($step === null) {
            return false;
        }

        // Atomar: von zwei gleichzeitigen Anfragen mit demselben Code gewinnt nur eine.
        $changed = $this->db->execute(
            'UPDATE users SET totp_last_step = :step WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :step)',
            ['step' => $step, 'id' => $userId],
        );

        return $changed === 1;
    }

    private function verifyRecovery(int $userId, string $input): bool
    {
        $code = self::normalizeRecoveryCode($input);
        if ($code === null) {
            return false;
        }

        $rows = $this->db->fetchAll('SELECT id, code_hash FROM recovery_codes WHERE user_id = :u AND used_at IS NULL', ['u' => $userId]);
        $matchedId = null;
        // Alle ungenutzten Codes prüfen, nicht beim Treffer abbrechen (gleichbleibende Laufzeit).
        foreach ($rows as $row) {
            if (isset($row['id'], $row['code_hash']) && is_int($row['id']) && is_string($row['code_hash']) && $this->hasher->verify($code, $row['code_hash'])) {
                $matchedId = $row['id'];
            }
        }
        if ($matchedId === null) {
            return false;
        }

        $changed = $this->db->execute(
            'UPDATE recovery_codes SET used_at = :t WHERE id = :id AND used_at IS NULL',
            ['t' => $this->clock->now()->format('c'), 'id' => $matchedId],
        );

        return $changed === 1;
    }

    /**
     * @return array{secret: string|null, enabled: bool, lastStep: int|null}
     */
    private function state(int $userId): array
    {
        $row = $this->db->fetchOne('SELECT totp_secret_enc, totp_enabled, totp_last_step FROM users WHERE id = :id', ['id' => $userId]);
        if ($row === null) {
            return ['secret' => null, 'enabled' => false, 'lastStep' => null];
        }

        $secret = null;
        if (isset($row['totp_secret_enc']) && is_string($row['totp_secret_enc'])) {
            $secret = $this->box->decrypt($row['totp_secret_enc']);
        }

        return [
            'secret' => $secret,
            'enabled' => isset($row['totp_enabled']) && $row['totp_enabled'] === 1,
            'lastStep' => isset($row['totp_last_step']) && is_int($row['totp_last_step']) ? $row['totp_last_step'] : null,
        ];
    }

    /**
     * 12 Zeichen Base32 = 60 Bit Zufall.
     */
    private static function newRecoveryCode(): string
    {
        return substr(Totp::base32Encode(random_bytes(8)), 0, 12);
    }

    private static function formatRecoveryCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private static function normalizeRecoveryCode(string $input): ?string
    {
        $code = strtoupper(str_replace(['-', ' '], '', $input));

        return preg_match('/^[A-Z2-7]{12}$/', $code) === 1 ? $code : null;
    }
}
