<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Security;

use Meridian\Security\SecretMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretMaskerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function patterns(): iterable
    {
        yield 'key in URL' => ['GET https://example.test/cron.php?key=TESTKEY0000fake', 'GET https://example.test/cron.php?key=••••'];
        yield 'token after &' => ['/run?a=1&token=abcdef123456&b=2', '/run?a=1&token=••••&b=2'];
        yield 'password at start' => ['password=hunter2hunter2', 'password=••••'];
        yield 'bearer header' => ['Authorization: Bearer eyJhbGciOi.abc.def', 'Authorization: Bearer ••••'];
        yield 'basic header' => ['authorization: basic dXNlcjpwYXNz', 'authorization: basic ••••'];
        yield 'api key header' => ['X-Api-Key: 1234567890abcdef', 'X-Api-Key: ••••'];
        yield 'credentials in URL' => ['https://alex:s3cr3t@nas.local/api', 'https://alex:••••@nas.local/api'];
        yield 'harmless text' => ['Starting scan for user 1 out of 2', 'Starting scan for user 1 out of 2'];
    }

    #[DataProvider('patterns')]
    public function testPatternsAreMasked(string $input, string $expected): void
    {
        self::assertSame($expected, (new SecretMasker())->mask($input));
    }

    public function testKnownSecretsAreMaskedInEveryEncoding(): void
    {
        $masker = new SecretMasker();
        $masker->remember('s3cr3t/value+x');

        $output = $masker->mask(implode(' | ', [
            'plain s3cr3t/value+x',
            'url ' . rawurlencode('s3cr3t/value+x'),
            'form ' . urlencode('s3cr3t/value+x'),
            'b64 ' . base64_encode('s3cr3t/value+x'),
        ]));

        self::assertStringNotContainsString('s3cr3t', $output);
        self::assertStringNotContainsString(base64_encode('s3cr3t/value+x'), $output);
        self::assertSame(4, substr_count($output, SecretMasker::MASK));
    }

    public function testLongestSecretWinsOverSubstring(): void
    {
        $masker = new SecretMasker();
        $masker->remember('abcd');
        $masker->remember('abcdefgh');

        self::assertSame('x ••••', $masker->mask('x abcdefgh'));
    }

    public function testVeryShortValuesAreNotRemembered(): void
    {
        $masker = new SecretMasker();
        $masker->remember('ok');

        self::assertSame('ok', $masker->mask('ok'));
    }
}
