<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Security;

use Meridian\Security\KeyLoader;
use Meridian\Security\SecretException;
use PHPUnit\Framework\TestCase;

final class KeyLoaderTest extends TestCase
{
    public function testTheKeyIsNeverReadFromAnEnvironmentVariable(): void
    {
        // Selbst ein gültiger Schlüssel in MERIDIAN_KEY wird ignoriert: Umgebungsvariablen sind in docker inspect sichtbar.
        $this->expectException(SecretException::class);
        KeyLoader::load(['MERIDIAN_KEY' => KeyLoader::generate()]);
    }

    public function testLoadsAValidKeyFile(): void
    {
        $file = $this->keyFile(KeyLoader::generate() . "\n", 0o600);

        try {
            self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen(KeyLoader::load(['MERIDIAN_KEY_FILE' => $file])));
        } finally {
            unlink($file);
        }
    }

    public function testRejectsAKeyFileThatOthersCanRead(): void
    {
        $file = $this->keyFile(KeyLoader::generate() . "\n", 0o644);

        try {
            $this->expectException(SecretException::class);
            KeyLoader::load(['MERIDIAN_KEY_FILE' => $file]);
        } finally {
            unlink($file);
        }
    }

    public function testRejectsMissingFilesAndWrongLength(): void
    {
        $short = $this->keyFile(base64_encode('zu-kurz') . "\n", 0o600);

        try {
            foreach (['/nicht/vorhanden', $short] as $path) {
                try {
                    KeyLoader::load(['MERIDIAN_KEY_FILE' => $path]);
                    self::fail('Eine Ausnahme wurde erwartet: ' . $path);
                } catch (SecretException) {
                    // erwartet
                }
            }
        } finally {
            unlink($short);
        }
    }

    public function testCredentialEnvironmentCannotAllowAnOrdinaryGroupReadableFile(): void
    {
        $file = $this->keyFile(KeyLoader::generate() . "\n", 0o440);
        try {
            $this->expectException(SecretException::class);
            KeyLoader::load(['MERIDIAN_KEY_FILE' => $file, 'CREDENTIALS_DIRECTORY' => dirname($file)]);
        } finally {
            chmod($file, 0o600);
            unlink($file);
        }
    }

    private function keyFile(string $content, int $mode): string
    {
        $file = tempnam(sys_get_temp_dir(), 'meridian-key-');
        self::assertIsString($file);
        file_put_contents($file, $content);
        chmod($file, $mode);

        return $file;
    }
}
