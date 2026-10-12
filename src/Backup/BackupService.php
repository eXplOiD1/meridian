<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Backup;

use Meridian\Auth\Clock;
use Meridian\Database\Connection;
use Meridian\Database\Timestamp;
use Meridian\Job\Row;
use Meridian\Security\SecretBox;

/**
 * Sicherung und Wiederherstellung der Datenbank (mer-storage §7).
 *
 *  - Gesichert wird nie per Dateikopie der laufenden WAL-Datenbank, sondern mit `VACUUM INTO` in eine `.tmp`-Datei; die wird
 *    geprüft (`quick_check`, Migrationstabelle lesbar) und erst dann umbenannt. Vorher wird der freie Platz geprüft.
 *  - Der Hauptschlüssel ist nie Teil der Sicherung. Vor der Wiederherstellung wird geprüft, ob der **aktuelle** Schlüssel einen
 *    gespeicherten geheimen Wert der Sicherung entschlüsselt, bevor die laufende Datenbank angefasst wird.
 *  - Wiederherstellung nimmt nur einen Dateinamen aus dem Sicherungsverzeichnis (feste Namensform), nie einen Pfad, verlangt
 *    einen angehaltenen Planer (Sperre frei), sichert den bisherigen Stand als `…-vor-wiederherstellung` und entfernt danach
 *    `-wal`/`-shm` des ersetzten Standes.
 */
final class BackupService
{
    public const NAME_PATTERN = '/^meridian-(\d{8})-(\d{6})(-vor-wiederherstellung)?\.sqlite$/D';
    public const PRE_RESTORE = '-vor-wiederherstellung';
    private const MIN_FREE_BYTES = 1_048_576;

    public function __construct(
        private readonly string $dataDir,
        private readonly string $databasePath,
        private readonly Clock $clock,
    ) {
    }

    public function directory(): string
    {
        return $this->dataDir . '/backups';
    }

    /**
     * @param string $suffix '' oder {@see self::PRE_RESTORE}
     *
     * @throws BackupFailed
     */
    public function create(Connection $db, string $suffix = ''): BackupInfo
    {
        if ($suffix !== '' && $suffix !== self::PRE_RESTORE) {
            throw new \InvalidArgumentException('Unbekannter Namenszusatz.');
        }
        $dir = $this->ensureDirectory();
        $this->assertSpace($dir);
        $name = 'meridian-' . $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His') . $suffix . '.sqlite';
        $final = $dir . '/' . $name;
        $tmp = $final . '.tmp';
        if (file_exists($final)) {
            throw new BackupFailed('Eine Sicherung mit diesem Namen gibt es schon (' . $name . '). In einer Sekunde nur eine Sicherung; bitte kurz warten und wiederholen.');
        }
        self::remove($tmp);
        try {
            $db->execute('VACUUM INTO :path', ['path' => $tmp]);
            self::verify($tmp);
            if (!chmod($tmp, 0o600) || !rename($tmp, $final)) {
                throw new BackupFailed('Die Sicherung konnte nicht abgelegt werden. Rechte und freien Platz im Verzeichnis „backups“ prüfen.');
            }
        } catch (BackupFailed $e) {
            self::remove($tmp);
            throw $e;
        } catch (\Throwable) {
            self::remove($tmp);
            throw new BackupFailed('Die Sicherung ist fehlgeschlagen. Freien Platz und Rechte im Datenverzeichnis prüfen; es wurde keine Sicherung angelegt.');
        }

        return $this->info($name) ?? throw new BackupFailed('Die Sicherung ist fehlgeschlagen.');
    }

    /**
     * @return list<BackupInfo> neueste zuerst
     */
    public function list(): array
    {
        $files = glob($this->directory() . '/meridian-*.sqlite');
        $out = [];
        foreach ($files === false ? [] : $files as $file) {
            $info = $this->info(basename($file));
            if ($info !== null) {
                $out[] = $info;
            }
        }
        usort($out, static fn (BackupInfo $a, BackupInfo $b): int => strcmp($b->name, $a->name));

        return $out;
    }

    /**
     * Behält die neuesten `$keep` regulären Sicherungen; Sicherungen „vor Wiederherstellung“ werden nie automatisch gelöscht.
     *
     * @return int Anzahl gelöschter Sicherungen
     */
    public function prune(int $keep): int
    {
        if ($keep < 1) {
            throw new \InvalidArgumentException('Mindestens eine Sicherung behalten.');
        }
        $regular = array_values(array_filter($this->list(), static fn (BackupInfo $i): bool => !str_contains($i->name, self::PRE_RESTORE)));
        $deleted = 0;
        foreach (array_slice($regular, $keep) as $info) {
            if (unlink($this->directory() . '/' . $info->name)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Spielt eine Sicherung ein. Prüft alles Mögliche, bevor die laufende Datenbank angefasst wird.
     *
     * @param string|null $key Hauptschlüssel (binär) oder null, wenn keiner geladen werden konnte
     *
     * @throws BackupFailed
     */
    public function restore(string $name, Connection $live, #[\SensitiveParameter] ?string $key): BackupInfo
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new BackupFailed('Ungültiger Dateiname. Erwartet wird eine Datei aus dem Sicherungsverzeichnis, z. B. meridian-20261011-020000.sqlite (Liste: backup:list).');
        }
        $source = $this->directory() . '/' . $name;
        if (!is_file($source)) {
            throw new BackupFailed('Diese Sicherung gibt es nicht (' . $name . '). Liste: backup:list.');
        }
        $lease = $live->fetchOne('SELECT expires_at FROM scheduler_lease WHERE id = 1');
        $expires = $lease === null ? null : Row::stringOrNull($lease, 'expires_at');
        if ($expires !== null && $expires !== '' && Timestamp::parse($expires) > $this->clock->now()) {
            throw new BackupFailed('Der Planer läuft noch. Zuerst Meridian anhalten (docker compose stop oder systemctl stop meridian-web meridian-scheduler), dann wiederherstellen.');
        }

        $staging = $this->databasePath . '.restore.tmp';
        self::remove($staging);
        try {
            if (!copy($source, $staging) || !chmod($staging, 0o600)) {
                throw new BackupFailed('Die Sicherung konnte nicht gelesen werden. Freien Platz und Rechte im Datenverzeichnis prüfen.');
            }
            self::verify($staging);
            self::checkKey($staging, $key);
            $this->create($live, self::PRE_RESTORE);
            self::remove($this->databasePath . '-wal');
            self::remove($this->databasePath . '-shm');
            if (!rename($staging, $this->databasePath)) {
                throw new BackupFailed('Die Datenbank konnte nicht ersetzt werden. Der bisherige Stand liegt als Sicherung „' . self::PRE_RESTORE . '“ vor.');
            }
        } catch (BackupFailed $e) {
            self::remove($staging);
            throw $e;
        } catch (\Throwable) {
            self::remove($staging);
            throw new BackupFailed('Die Wiederherstellung ist fehlgeschlagen; die laufende Datenbank wurde nicht geändert.');
        }

        return $this->info($name) ?? throw new BackupFailed('Die Wiederherstellung ist fehlgeschlagen.');
    }

    private function info(string $name): ?BackupInfo
    {
        if (preg_match(self::NAME_PATTERN, $name, $m) !== 1) {
            return null;
        }
        $created = \DateTimeImmutable::createFromFormat('!Ymd-His', $m[1] . '-' . $m[2], new \DateTimeZone('UTC'));
        $bytes = @filesize($this->directory() . '/' . $name);
        if ($created === false || $bytes === false) {
            return null;
        }

        return new BackupInfo($name, $created, $bytes);
    }

    private function ensureDirectory(): string
    {
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0o700) && !is_dir($dir)) {
            throw new BackupFailed('Das Sicherungsverzeichnis konnte nicht angelegt werden. Rechte im Datenverzeichnis prüfen.');
        }
        if (!is_writable($dir)) {
            throw new BackupFailed('Das Sicherungsverzeichnis ist nicht beschreibbar. Rechte prüfen.');
        }

        return $dir;
    }

    private function assertSpace(string $dir): void
    {
        $need = self::size($this->databasePath) + self::size($this->databasePath . '-wal');
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $need + self::MIN_FREE_BYTES) {
            throw new BackupFailed('Zu wenig freier Platz für eine Sicherung. Platz schaffen oder alte Sicherungen löschen.');
        }
    }

    /**
     * @throws BackupFailed
     */
    private static function verify(string $file): void
    {
        try {
            $db = Connection::open($file);
            $check = $db->fetchOne('PRAGMA quick_check');
            $migrations = $db->fetchOne('SELECT COUNT(*) AS n FROM schema_migrations');
            $db->fetchOne('PRAGMA wal_checkpoint(TRUNCATE)');
            unset($db);
        } catch (\Throwable) {
            self::remove($file . '-wal');
            self::remove($file . '-shm');
            throw new BackupFailed('Die Datenbankdatei ist beschädigt oder keine Meridian-Datenbank.');
        }
        self::remove($file . '-wal');
        self::remove($file . '-shm');
        $migrationCount = $migrations['n'] ?? null;
        if (($check['quick_check'] ?? null) !== 'ok' || !is_int($migrationCount) || $migrationCount < 1) {
            throw new BackupFailed('Die Datenbankdatei ist beschädigt oder keine Meridian-Datenbank.');
        }
    }

    /**
     * Entschlüsselt je einen gespeicherten geheimen Wert mit dem aktuellen Schlüssel.
     *
     * @throws BackupFailed
     */
    private static function checkKey(string $file, #[\SensitiveParameter] ?string $key): void
    {
        $db = Connection::open($file);
        /** @var list<string> $samples */
        $samples = [];
        foreach ([
            $db->fetchOne('SELECT totp_secret_enc AS v FROM users WHERE totp_secret_enc IS NOT NULL LIMIT 1'),
            $db->fetchOne('SELECT payload_enc AS v FROM jobs WHERE payload_enc IS NOT NULL LIMIT 1'),
            $db->fetchOne('SELECT secret_enc AS v FROM notification_channels WHERE secret_enc IS NOT NULL LIMIT 1'),
        ] as $row) {
            $value = $row === null ? null : Row::stringOrNull($row, 'v');
            if ($value !== null && $value !== '') {
                $samples[] = $value;
            }
        }
        $db->fetchOne('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($db);
        self::remove($file . '-wal');
        self::remove($file . '-shm');
        if ($samples === []) {
            return;
        }
        if ($key === null) {
            throw new BackupFailed('Die Sicherung enthält verschlüsselte Werte, aber es ist kein Hauptschlüssel geladen. Zuerst die Schlüsseldatei an ihren Platz legen (MERIDIAN_KEY_FILE).');
        }
        $box = new SecretBox($key);
        foreach ($samples as $sample) {
            try {
                $box->decrypt($sample);
            } catch (\Throwable) {
                throw new BackupFailed('Der Hauptschlüssel passt nicht zu dieser Sicherung. Den Schlüssel verwenden, mit dem sie erstellt wurde; die laufende Datenbank wurde nicht geändert.');
            }
        }
    }

    private static function size(string $file): int
    {
        $size = @filesize($file);

        return $size === false ? 0 : $size;
    }

    private static function remove(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
