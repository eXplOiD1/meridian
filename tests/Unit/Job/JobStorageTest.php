<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Job;

use Meridian\Job\HttpJobConfig;
use Meridian\Job\HttpMethod;
use Meridian\Job\InvalidJobConfig;
use Meridian\Job\JobSort;
use Meridian\Job\StoreResponse;
use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\JobType;
use Meridian\Security\CategoryScope;
use Meridian\Settings\DisplayPathMode;
use Meridian\Settings\SettingKey;
use Meridian\Tests\Support\JobHarness;
use PHPUnit\Framework\TestCase;

/**
 * Roundtrip über eine neue Verbindung (mer-storage §8): config_json ohne Geheimnis, display_url exakt,
 * payload_enc als v1-Chiffretext, der zum Original entschlüsselt.
 */
final class JobStorageTest extends TestCase
{
    use JobHarness;

    protected function setUp(): void
    {
        $this->setUpJobDatabase();
        $this->settings()->set(\Meridian\Settings\SettingKey::HttpDisplayPath, 'auto', 1);
    }

    protected function tearDown(): void
    {
        $this->tearDownJobDatabase();
    }

    /**
     * @return array<string, mixed>
     */
    private function secretInput(): array
    {
        return self::input([
            'http' => ['method' => 'POST', 'timeout_seconds' => 45, 'expected_status' => '200,204,301-302', 'max_redirects' => 2, 'store_response' => 'on'],
            'request' => [
                'url' => 'https://api.example.org/hooks/' . self::secret('path') . '/run?key=' . self::secret('query') . '&mode=',
                'headers' => [['name' => 'Authorization', 'value' => 'Bearer ' . self::secret('header')]],
                'body' => '{"token":"' . self::secret('body') . '"}',
            ],
        ]);
    }

    public function testRoundtripThroughANewConnection(): void
    {
        $draft = $this->draft($this->secretInput());
        $id = $this->jobs()->insert($draft, ownerId: 7);

        $fresh = $this->freshConnection();
        $row = $fresh->fetchOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        $config = self::text($row['config_json']);
        $payload = self::text($row['payload_enc']);
        foreach (['path', 'query', 'header', 'body'] as $part) {
            $secret = self::secret($part);
            foreach ([$secret, rawurlencode($secret), base64_encode($secret)] as $form) {
                self::assertStringNotContainsString($form, $config, 'config_json ohne ' . $part);
                self::assertStringNotContainsString($form, $payload, 'payload_enc nur verschlüsselt: ' . $part);
            }
        }
        $decoded = json_decode($config, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('https://api.example.org/hooks/••••/run?key=••••&mode=••••', self::dig($decoded, 'http', 'display_url'));
        self::assertSame(['scheme' => 'https', 'host' => 'api.example.org', 'port' => 443], self::dig($decoded, 'http', 'target'));
        self::assertSame(1, self::dig($decoded, 'v'));
        self::assertTrue(self::dig($decoded, 'http', 'has_headers'));
        self::assertSame(1, self::dig($decoded, 'http', 'header_count'));
        self::assertTrue(self::dig($decoded, 'http', 'has_body'));
        self::assertSame([[200, 200], [204, 204], [301, 302]], self::dig($decoded, 'http', 'expected_status'));

        self::assertStringStartsWith('v1:', $payload);
        $plain = $this->box()->decrypt($payload);
        self::assertNotNull($draft->payload);
        self::assertSame($draft->payload->toJson(), $plain);
        self::assertSame('https://api.example.org/hooks/' . self::secret('path') . '/run?key=' . self::secret('query') . '&mode=', HttpPayload::fromJson($plain)->url()->toUrl());

        // Lesen über die neue Verbindung: alle Felder, nie das Payload.
        $job = $this->jobs($fresh)->findVisible($id, CategoryScope::everything());
        self::assertNotNull($job);
        self::assertSame('Hub', $job->name);
        self::assertSame(JobType::Http, $job->type);
        self::assertSame('A', $job->categoryName);
        self::assertSame(7, $job->ownerId);
        self::assertNull($job->nextRunAt, 'Anlegen setzt keinen Termin; Planner::reschedule() folgt (H6)');
        self::assertNotNull($job->http);
        self::assertSame(HttpMethod::Post, $job->http->method);
        self::assertSame(45, $job->http->timeoutSeconds);
        self::assertSame('200,204,301-302', $job->http->expectedStatusText());
        self::assertSame(StoreResponse::On, $job->http->storeResponse);
        self::assertSame('https://api.example.org', $job->http->target());
        self::assertSame('https://api.example.org/hooks/••••/run?key=••••&mode=••••', $job->http->displayUrl);
        self::assertSame(1, $job->http->headerCount);
        self::assertTrue($job->http->hasBody);
    }

    public function testUpdateWithoutRequestKeepsTheStoredPayloadAndTarget(): void
    {
        $id = $this->jobs()->insert($this->draft($this->secretInput()), 1);
        $before = $this->rawJob($id);
        $existing = $this->jobs()->findVisible($id, CategoryScope::everything());
        self::assertNotNull($existing);

        $draft = $this->validator()->validate(['name' => 'Neu', 'http' => ['timeout_seconds' => 10]], CategoryScope::everything(), $existing);
        self::assertNull($draft->payload);
        $this->jobs()->update($id, $draft, resetSchedule: false);

        $after = $this->rawJob($id);
        self::assertSame($before['payload_enc'], $after['payload_enc'], 'payload_enc bleibt unberührt');
        self::assertSame('Neu', $after['name']);
        $config = HttpJobConfig::fromJson(self::text($after['config_json']));
        self::assertSame(10, $config->timeoutSeconds);
        self::assertSame('https://api.example.org/hooks/••••/run?key=••••&mode=••••', $config->displayUrl);
        self::assertSame(HttpMethod::Post, $config->method, 'nicht genannte Felder bleiben');
        self::assertTrue($config->hasBody);
    }

    public function testReplacingTheRequestReplacesAllThreePartsAndRebuildsTheDerivedFlags(): void
    {
        $id = $this->jobs()->insert($this->draft($this->secretInput()), 1);
        $existing = $this->jobs()->findVisible($id, CategoryScope::everything());
        self::assertNotNull($existing);
        $oldPayload = $this->rawJob($id)['payload_enc'];

        $draft = $this->validator()->validate(['http' => ['method' => 'GET'], 'request' => ['url' => 'http://other.example.org:8080/status']], CategoryScope::everything(), $existing);
        $this->jobs()->update($id, $draft, resetSchedule: true);

        $config = HttpJobConfig::fromJson(self::text($this->rawJob($id)['config_json']));
        self::assertSame('http://other.example.org:8080', $config->target());
        self::assertSame('http://other.example.org:8080/status', $config->displayUrl);
        self::assertFalse($config->hasHeaders);
        self::assertSame(0, $config->headerCount);
        self::assertFalse($config->hasBody, 'fehlender Body = kein Body');
        $payload = HttpPayload::fromJson($this->box()->decrypt(self::text($this->rawJob($id)['payload_enc'])));
        self::assertSame([], $payload->headers());
        self::assertNull($payload->body());
        self::assertNotSame($oldPayload, $this->rawJob($id)['payload_enc']);
    }

    public function testUpdateResetsNextRunOnlyWhenAsked(): void
    {
        $id = $this->jobs()->insert($this->draft(self::input()), 1);
        $this->db()->execute("UPDATE jobs SET next_run_at = '2026-10-07T09:00:00+00:00' WHERE id = :id", ['id' => $id]);
        $existing = $this->jobs()->findVisible($id, CategoryScope::everything());
        self::assertNotNull($existing);
        $draft = $this->validator()->validate(['name' => 'Anders'], CategoryScope::everything(), $existing);

        $this->jobs()->update($id, $draft, resetSchedule: false);
        self::assertSame('2026-10-07T09:00:00+00:00', $this->rawJob($id)['next_run_at']);

        $this->jobs()->update($id, $draft, resetSchedule: true);
        self::assertNull($this->rawJob($id)['next_run_at']);
    }

    public function testSetEnabledClearsTheNextRunInOneStatement(): void
    {
        $id = $this->jobs()->insert($this->draft(self::input()), 1);
        $this->db()->execute("UPDATE jobs SET next_run_at = '2026-10-07T09:00:00+00:00' WHERE id = :id", ['id' => $id]);

        $this->jobs()->setEnabled($id, false);

        $row = $this->rawJob($id);
        self::assertSame(0, $row['is_enabled']);
        self::assertNull($row['next_run_at']);
    }

    public function testListAndDetailUseTheSameVisibilityRule(): void
    {
        $this->seedJobs();
        $scopes = [
            'alle' => CategoryScope::everything(),
            'nichts' => CategoryScope::nothing(),
            'nur A' => CategoryScope::only(['A']),
            'nur B' => CategoryScope::only(['B']),
            'A und B' => CategoryScope::only(['A', 'B']),
            'unbekannt' => CategoryScope::only(['Z']),
        ];
        $categoryOf = ['A' => 'A', 'B' => 'B', 'N' => null];
        foreach ($scopes as $label => $scope) {
            $listed = [];
            foreach ($this->jobs()->list($scope, null, null, false, JobSort::Name)->jobs as $job) {
                $listed[] = $job->name;
            }
            $detail = [];
            foreach (['A', 'B', 'N'] as $name) {
                $id = $this->jobIdByName($name);
                if ($this->jobs()->findVisible($id, $scope) !== null) {
                    $detail[] = $name;
                }
            }
            $expected = array_values(array_filter(['A', 'B', 'N'], static fn (string $n): bool => $scope->contains($categoryOf[$n])));
            sort($listed);
            self::assertSame($expected, $listed, 'Liste: ' . $label);
            self::assertSame($expected, $detail, 'Detail: ' . $label);
        }
    }

    public function testCategoryFilterIsOnlyARequestInsideTheScope(): void
    {
        $this->seedJobs();

        $onlyA = CategoryScope::only(['A']);
        self::assertSame([], $this->jobs()->list($onlyA, null, 2, false, JobSort::Name)->jobs, 'category=B für den A-Bereich bleibt leer');
        self::assertCount(1, $this->jobs()->list($onlyA, null, 1, false, JobSort::Name)->jobs);
        self::assertCount(1, $this->jobs()->list(CategoryScope::everything(), null, 2, false, JobSort::Name)->jobs);
    }

    public function testListSortsAndFiltersEnabled(): void
    {
        $this->seedJobs();
        $this->db()->execute("UPDATE jobs SET is_enabled = 0 WHERE name = 'B'");
        $this->db()->execute("UPDATE jobs SET next_run_at = '2026-10-07T10:00:00+00:00' WHERE name = 'N'");
        $this->db()->execute("UPDATE jobs SET next_run_at = '2026-10-07T09:00:00+00:00' WHERE name = 'A'");
        $all = CategoryScope::everything();

        $byNext = array_map(static fn ($j): string => $j->name, $this->jobs()->list($all, null, null, false, JobSort::NextRun)->jobs);
        self::assertSame(['A', 'N', 'B'], $byNext, 'ohne Termin zuletzt');
        $enabled = array_map(static fn ($j): string => $j->name, $this->jobs()->list($all, null, null, true, JobSort::Name)->jobs);
        self::assertSame(['A', 'N'], $enabled);
        self::assertSame([], $this->jobs()->list($all, JobType::Shell, null, false, JobSort::Name)->jobs);
    }

    public function testListIsLimitedAndMarkedTruncated(): void
    {
        $this->seedJobs();
        $this->db()->execute(
            'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 510)
             INSERT INTO jobs (name, type, category_id, cron, timezone, config_json, payload_enc, created_at, updated_at)
             SELECT \'x\' || i, \'shell\', 1, \'* * * * *\', \'UTC\', \'{}\', \'v1:x\', \'2026-10-07T00:00:00+00:00\', \'2026-10-07T00:00:00+00:00\' FROM n',
        );

        $listing = $this->jobs()->list(CategoryScope::everything(), null, null, false, JobSort::Name);

        self::assertCount(500, $listing->jobs);
        self::assertTrue($listing->truncated);
    }

    public function testLastRunIgnoresTestRunsAndUnfinishedRuns(): void
    {
        $id = $this->jobs()->insert($this->draft(self::input()), 1);
        $insert = static fn (string $trigger, string $status): array => ['trigger' => $trigger, 'status' => $status];
        foreach ([$insert('schedule', 'ok'), $insert('schedule', 'failed'), $insert('test', 'ok'), $insert('manual', 'queued'), $insert('schedule', 'running'), $insert('schedule', 'skipped')] as $run) {
            $this->db()->execute(
                'INSERT INTO runs (job_id, trigger, status, finished_at, http_status) VALUES (:job, :trigger, :status, :fin, 200)',
                ['job' => $id, 'fin' => '2026-10-07T08:00:00+00:00'] + $run,
            );
        }

        $job = $this->jobs()->findVisible($id, CategoryScope::everything());

        self::assertNotNull($job);
        self::assertNotNull($job->lastRun);
        self::assertSame('failed', $job->lastRun->status->value, 'letzter abgeschlossener Lauf ohne Testlauf, übersprungen und laufend zählen nicht');
        self::assertTrue($job->running);
    }

    public function testConfigJsonIsReadStrictly(): void
    {
        $good = $this->draft(self::input())->http()->toJson();
        self::assertSame($good, HttpJobConfig::fromJson($good)->toJson());

        $decoded = json_decode($good, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $withoutVersion = $decoded;
        unset($withoutVersion['v']);
        $extra = $decoded;
        $extra['surprise'] = 1;
        $wrongVersion = $decoded;
        $wrongVersion['v'] = 2;
        $badTimeout = $decoded;
        $badTimeout['http'] = ['timeout_seconds' => '30'] + (is_array($decoded['http']) ? $decoded['http'] : []);
        foreach (['', 'kein json', '[]', json_encode($withoutVersion), json_encode($extra), json_encode($wrongVersion), json_encode($badTimeout)] as $broken) {
            try {
                HttpJobConfig::fromJson((string) $broken);
                self::fail('Muss abgelehnt werden: ' . $broken);
            } catch (InvalidJobConfig $e) {
                self::assertStringNotContainsString('timeout', $e->getMessage(), 'feste Meldung ohne Inhalt');
            }
        }
    }

    public function testDisplayUrlFollowsTheDisplayPathSettingAtSaveTime(): void
    {
        $this->settings()->set(SettingKey::HttpDisplayPath, DisplayPathMode::Hidden->value, 1);

        $draft = $this->draft(self::input(['request' => ['url' => 'https://api.example.org/status/page?mode=x']]));

        self::assertSame('https://api.example.org/••••?••••', $draft->http()->displayUrl);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawJob(int $id): array
    {
        $row = $this->freshConnection()->fetchOne('SELECT * FROM jobs WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    private function seedJobs(): void
    {
        $this->jobs()->insert($this->draft(self::input(['name' => 'A', 'category_id' => 1])), 1);
        $this->jobs()->insert($this->draft(self::input(['name' => 'B', 'category_id' => 2])), 1);
        $this->jobs()->insert($this->draft(self::input(['name' => 'N', 'category_id' => null])), 1);
    }

    private function jobIdByName(string $name): int
    {
        $row = $this->db()->fetchOne('SELECT id FROM jobs WHERE name = :n', ['n' => $name]);
        self::assertIsArray($row);

        return self::intValue($row['id']);
    }

    private static function dig(mixed $data, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }
}
