<?php

declare(strict_types=1);
namespace Meridian\Tests\Release;
use Meridian\Tests\Support\ApiHarness;
use PHPUnit\Framework\TestCase;
final class JobEditorModeApiTest extends TestCase
{
    use ApiHarness;
    public function testPreferenceIsValidatedSavedPerUserAndRestoredOnLogin(): void
    {
        $admin = $this->users()->create('admin', 'Admin', self::PASSWORD, 'admin');
        $other = $this->users()->create('other', 'Other', self::PASSWORD, 'admin');
        $login = $this->login('admin');
        self::assertSame('simple', $this->json($login)['job_editor_mode']);
        $cookies = [self::COOKIE => $this->sessionCookie($login)];
        $headers = ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($login)];
        $url = '/api/auth/job-editor-mode';
        self::assertSame(403, $this->call('PUT', $url, ['job_editor_mode' => 'expert'], ['Origin' => 'https://meridian.test'], $cookies)->getStatusCode());
        foreach (['unknown', null, 1] as $invalid) {
            self::assertSame(422, $this->call('PUT', $url, ['job_editor_mode' => $invalid], $headers, $cookies)->getStatusCode());
        }
        foreach (['expert', 'simple'] as $mode) {
            $saved = $this->call('PUT', $url, ['job_editor_mode' => $mode], $headers, $cookies);
            self::assertSame(200, $saved->getStatusCode());
            self::assertSame($mode, $this->json($saved)['job_editor_mode']);
            self::assertSame($mode, $this->users()->jobEditorModeOf($admin));
            self::assertSame('simple', $this->users()->jobEditorModeOf($other));
            self::assertSame($mode, $this->json($this->call('GET', '/api/auth/me', [], [], $cookies))['job_editor_mode']);
            self::assertSame($mode, $this->json($this->login('admin'))['job_editor_mode']);
        }
    }
}
