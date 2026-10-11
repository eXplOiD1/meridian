<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Http;

use Meridian\Job\RunService;
use Meridian\Runner\JobType;
use Meridian\Runner\RunResult;
use Meridian\Tests\Support\FakeRunner;
use Meridian\Tests\Support\JobApiHarness;
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Manueller Lauf und Testlauf (S8): Rollen-Matrix, IDOR, CSRF, 409/429, Audit, Leak-Test und der Weg bis zum Worker.
 */
final class JobRunApiTest extends TestCase
{
    use JobApiHarness;

    protected function setUp(): void
    {
        $this->seedJobs();
    }

    private function runCount(): int
    {
        $n = self::dig($this->db()->fetchOne('SELECT COUNT(*) AS n FROM runs'), 'n');
        self::assertIsInt($n);

        return $n;
    }

    private function runIdOf(\Symfony\Component\HttpFoundation\Response $response): int
    {
        $runId = self::dig($this->json($response), 'run_id');
        self::assertIsInt($runId, (string) $response->getContent());

        return $runId;
    }

    private function userId(string $username): int
    {
        $this->as($username);
        $id = self::dig($this->db()->fetchOne('SELECT id FROM users WHERE username = :u', ['u' => $username]), 'id');
        self::assertIsInt($id);

        return $id;
    }

    /**
     * @return array<string, array{?string, string, int}> Rolle, Job, Status
     */
    public static function matrix(): array
    {
        return [
            'anonym' => [null, 'JA', 401],
            'Admin in A' => ['admin', 'JA', 202],
            'Admin in B' => ['admin', 'JB', 202],
            'Admin ohne Kategorie' => ['admin', 'JN', 202],
            'Operator in B' => ['operator', 'JB', 202],
            'Operator ohne Kategorie' => ['operator', 'JN', 202],
            'Operator nur A in A' => ['operatorA', 'JA', 202],
            'Operator nur A in B (IDOR)' => ['operatorA', 'JB', 404],
            'Operator nur A ohne Kategorie (IDOR)' => ['operatorA', 'JN', 404],
            'Beobachter' => ['observer', 'JA', 403],
            'Beobachter nur A in A' => ['observerA', 'JA', 403],
            'Beobachter nur A in B (IDOR)' => ['observerA', 'JB', 404],
        ];
    }

    #[DataProvider('matrix')]
    public function testManualRunMatrix(?string $who, string $job, int $status): void
    {
        $response = $this->api($who, 'POST', '/api/jobs/' . $this->jobIds[$job] . '/run');

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($status === 202 ? 1 : 0, $this->runCount(), 'nur ein erfolgreiches Einreihen schreibt');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame($status === 202 ? ['job.run_manual'] : [], $this->auditActions(), 'abgelehnte Anfragen werden nicht protokolliert');
    }

    #[DataProvider('matrix')]
    public function testTestRunMatrix(?string $who, string $job, int $status): void
    {
        $response = $this->api($who, 'POST', '/api/jobs/' . $this->jobIds[$job] . '/test');

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($status === 202 ? 1 : 0, $this->runCount());
        self::assertSame($status === 202 ? ['job.run_test'] : [], $this->auditActions());
    }

    public function testUnknownJobIs404AndNotARunOfSomethingElse(): void
    {
        self::assertSame(404, $this->api('admin', 'POST', '/api/jobs/99999/run')->getStatusCode());
        self::assertSame(404, $this->api('admin', 'POST', '/api/jobs/abc/run')->getStatusCode());
        self::assertSame(0, $this->runCount());
    }

    public function testGetOnRunEndpointsIsNotAllowed(): void
    {
        self::assertSame(405, $this->api('admin', 'GET', '/api/jobs/' . $this->jobIds['JA'] . '/run')->getStatusCode());
        self::assertSame(405, $this->api('admin', 'GET', '/api/jobs/' . $this->jobIds['JA'] . '/test')->getStatusCode());
    }

    // --- CSRF ----------------------------------------------------------------------------------------

    public function testCsrfIsCheckedBeforeAnythingIsLoadedOrWritten(): void
    {
        foreach (['run', 'test'] as $kind) {
            $path = '/api/jobs/' . $this->jobIds['JA'] . '/' . $kind;
            self::assertSame(403, $this->api('admin', 'POST', $path, [], csrf: false)->getStatusCode(), 'Token fehlt');
            $session = $this->as('admin');
            $wrong = $this->call('POST', $path, [], ['Origin' => self::ORIGIN, 'X-CSRF-Token' => 'falsch'], [self::COOKIE => $session['cookie']]);
            self::assertSame(403, $wrong->getStatusCode(), 'Token falsch');
            $foreign = $this->call('POST', $path, [], ['Origin' => 'https://evil.test', 'X-CSRF-Token' => $session['csrf']], [self::COOKIE => $session['cookie']]);
            self::assertSame(403, $foreign->getStatusCode(), 'fremder Origin');
        }
        // Auch für einen unbekannten Job: CSRF kommt vor dem Laden (403, nicht 404).
        self::assertSame(403, $this->api('admin', 'POST', '/api/jobs/99999/run', [], csrf: false)->getStatusCode());
        self::assertSame(0, $this->runCount());
        self::assertSame([], $this->auditActions());
    }

    // --- Inhalt des Laufs, Audit, Roundtrip --------------------------------------------------------------

    public function testQueuedRunCarriesTriggerStarterAndNowAndIsReadableThroughTheRunDetail(): void
    {
        $response = $this->api('operator', 'POST', '/api/jobs/' . $this->jobIds['JA'] . '/run');
        $runId = $this->runIdOf($response);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['run_id' => $runId], $this->json($response), 'nur die Nummer, keine weiteren Felder');

        // Roundtrip: die Zeile, wie sie die Datenbank hält.
        $row = $this->db()->fetchOne('SELECT job_id, trigger, status, attempt, scheduled_for, started_by, started_at, output, note FROM runs WHERE id = :id', ['id' => $runId]);
        self::assertSame([
            'job_id' => $this->jobIds['JA'],
            'trigger' => 'manual',
            'status' => 'queued',
            'attempt' => 1,
            'scheduled_for' => '2026-10-07T08:00:00+00:00',
            'started_by' => $this->userId('operator'),
            'started_at' => null,
            'output' => null,
            'note' => null,
        ], $row);

        // Abfrage des Ergebnisses über das bestehende Lauf-Detail.
        $detail = $this->api('observer', 'GET', '/api/runs/' . $runId);
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame('queued', self::dig($this->json($detail), 'run', 'status'));
        self::assertSame('manual', self::dig($this->json($detail), 'run', 'trigger'));
        self::assertSame('Operator', self::dig($this->json($detail), 'run', 'started_by', 'display_name'));
        // IDOR: ein Operator nur für A sieht den Lauf eines Jobs in B nicht.
        $other = $this->runIdOf($this->api('admin', 'POST', '/api/jobs/' . $this->jobIds['JB'] . '/run'));
        self::assertSame(404, $this->api('operatorA', 'GET', '/api/runs/' . $other)->getStatusCode());
    }

    public function testTestRunIsMarkedAsTestAndAuditNamesJobAndRun(): void
    {
        $runId = $this->runIdOf($this->api('admin', 'POST', '/api/jobs/' . $this->jobIds['JA'] . '/test'));

        self::assertSame('test', self::dig($this->db()->fetchOne('SELECT trigger FROM runs WHERE id = :id', ['id' => $runId]), 'trigger'));
        $audit = $this->db()->fetchOne("SELECT user_id, action, target FROM audit_log WHERE action LIKE 'job.run_%'");
        self::assertSame(['user_id' => $this->userId('admin'), 'action' => 'job.run_test', 'target' => 'job:' . $this->jobIds['JA'] . ' JA; run:' . $runId], $audit);
    }

    // --- Deaktivierte Jobs ---------------------------------------------------------------------------

    public function testManualRunOfDisabledJobIs409ButTestRunIs202(): void
    {
        $id = $this->jobIds['JA'];
        $this->db()->execute('UPDATE jobs SET is_enabled = 0, next_run_at = NULL WHERE id = :id', ['id' => $id]);

        $manual = $this->api('admin', 'POST', '/api/jobs/' . $id . '/run');
        self::assertSame(409, $manual->getStatusCode());
        self::assertStringContainsString('deaktiviert', $this->plain($manual));
        self::assertSame(0, $this->runCount());
        self::assertSame([], $this->auditActions());

        self::assertSame(202, $this->api('admin', 'POST', '/api/jobs/' . $id . '/test')->getStatusCode());
        self::assertSame(['job.run_test'], $this->auditActions());
    }

    public function testRightsAreCheckedBeforeStateSoAForbiddenRoleLearnsNothingAboutTheJob(): void
    {
        $id = $this->jobIds['JA'];
        $this->db()->execute('UPDATE jobs SET is_enabled = 0 WHERE id = :id', ['id' => $id]);

        self::assertSame(403, $this->api('observer', 'POST', '/api/jobs/' . $id . '/run')->getStatusCode(), 'Beobachter: 403, nicht 409');
        self::assertSame(404, $this->api('operatorA', 'POST', '/api/jobs/' . $this->jobIds['JB'] . '/run')->getStatusCode());
    }

    // --- Ein offener Lauf je Job ------------------------------------------------------------------------------

    public function testOnlyOneOpenManualOrTestRunPerJob(): void
    {
        $id = $this->jobIds['JA'];
        $first = $this->api('admin', 'POST', '/api/jobs/' . $id . '/run');
        self::assertSame(202, $first->getStatusCode());

        foreach (['run', 'test'] as $kind) {
            $again = $this->api('operator', 'POST', '/api/jobs/' . $id . '/' . $kind);
            self::assertSame(409, $again->getStatusCode(), 'queued blockiert, auch für andere Benutzer und den anderen Auslöser');
        }
        self::assertSame(1, $this->runCount());
        self::assertSame(['job.run_manual'], $this->auditActions());

        // Ein anderer Job ist nicht betroffen.
        self::assertSame(202, $this->api('admin', 'POST', '/api/jobs/' . $this->jobIds['JB'] . '/run')->getStatusCode());

        // Auch ein laufender Lauf blockiert; ein beendeter nicht mehr.
        $runId = $this->runIdOf($first);
        $this->db()->execute("UPDATE runs SET status = 'running' WHERE id = :id", ['id' => $runId]);
        self::assertSame(409, $this->api('admin', 'POST', '/api/jobs/' . $id . '/test')->getStatusCode());
        $this->db()->execute("UPDATE runs SET status = 'ok' WHERE id = :id", ['id' => $runId]);
        self::assertSame(202, $this->api('admin', 'POST', '/api/jobs/' . $id . '/test')->getStatusCode());
    }

    public function testAnOpenScheduledRunDoesNotBlockAManualRun(): void
    {
        $this->db()->execute("INSERT INTO runs (job_id, trigger, status, scheduled_for) VALUES (:j, 'schedule', 'running', '2026-10-07T08:00:00+00:00')", ['j' => $this->jobIds['JA']]);

        self::assertSame(202, $this->api('admin', 'POST', '/api/jobs/' . $this->jobIds['JA'] . '/run')->getStatusCode(), 'Die Überlappung regelt der Worker, nicht diese Grenze.');
    }

    // --- 60 je Benutzer und Stunde --------------------------------------------------------------------------------

    private function seedFinishedRuns(int $userId, int $count, string $scheduledFor, string $trigger = 'manual'): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->db()->execute(
                "INSERT INTO runs (job_id, trigger, status, scheduled_for, started_by, finished_at) VALUES (:j, :t, 'ok', :s, :u, :s)",
                ['j' => $this->jobIds['JN'], 't' => $trigger, 's' => $scheduledFor, 'u' => $userId],
            );
        }
    }

    public function testSixtyFirstRunWithinAnHourIs429(): void
    {
        $admin = $this->userId('admin');
        $this->seedFinishedRuns($admin, 59, '2026-10-07T07:30:00+00:00');
        $this->seedFinishedRuns($admin, 1, '2026-10-07T07:30:00+00:00', 'test');
        $path = '/api/jobs/' . $this->jobIds['JA'];

        self::assertSame(RunService::MAX_PER_HOUR, 60);
        $limited = $this->api('admin', 'POST', $path . '/run');
        self::assertSame(429, $limited->getStatusCode(), 'manuelle und Testläufe zählen zusammen');
        self::assertSame(429, $this->api('admin', 'POST', $path . '/test')->getStatusCode());
        self::assertSame(60, $this->runCount(), 'nichts eingereiht');
        self::assertSame([], $this->auditActions());

        // Andere Benutzer haben ihr eigenes Kontingent.
        $operatorRun = $this->api('operator', 'POST', $path . '/run');
        self::assertSame(202, $operatorRun->getStatusCode());
        $this->db()->execute("UPDATE runs SET status = 'ok' WHERE id = :id", ['id' => $this->runIdOf($operatorRun)]);

        // Eine Stunde später ist das Kontingent wieder frei.
        $this->clock()->advance(3600 - 30 * 60 + 1);
        self::assertSame(202, $this->api('admin', 'POST', $path . '/run')->getStatusCode());
    }

    public function testFiftyNineRunsStillPassAndTheSixtiethIsTheLast(): void
    {
        $admin = $this->userId('admin');
        $this->seedFinishedRuns($admin, 59, '2026-10-07T07:30:00+00:00');
        $path = '/api/jobs/' . $this->jobIds['JA'];

        $first = $this->api('admin', 'POST', $path . '/run');
        self::assertSame(202, $first->getStatusCode(), 'der 60. Lauf');
        $this->db()->execute("UPDATE runs SET status = 'ok' WHERE id = :id", ['id' => $this->runIdOf($first)]);
        self::assertSame(429, $this->api('admin', 'POST', $path . '/run')->getStatusCode(), 'der 61. Lauf');
    }

    public function testScheduledRunsAndRetriesDoNotCountTowardsTheQuota(): void
    {
        $admin = $this->userId('admin');
        $this->seedFinishedRuns($admin, 100, '2026-10-07T07:30:00+00:00', 'retry');

        self::assertSame(202, $this->api('admin', 'POST', '/api/jobs/' . $this->jobIds['JA'] . '/run')->getStatusCode());
    }

    // --- Statistik ------------------------------------------------------------------------------------------------

    public function testTestRunsDoNotCountAsLastRun(): void
    {
        $id = $this->jobIds['JA'];
        $runId = $this->runIdOf($this->api('admin', 'POST', '/api/jobs/' . $id . '/test'));
        $this->db()->execute("UPDATE runs SET status = 'failed', finished_at = '2026-10-07T08:00:05+00:00' WHERE id = :id", ['id' => $runId]);

        self::assertNull(self::dig($this->json($this->api('admin', 'GET', '/api/jobs/' . $id)), 'job', 'last_run'));

        $manual = $this->runIdOf($this->api('admin', 'POST', '/api/jobs/' . $id . '/run'));
        $this->db()->execute("UPDATE runs SET status = 'ok', finished_at = '2026-10-07T08:00:09+00:00' WHERE id = :id", ['id' => $manual]);
        self::assertSame($manual, self::dig($this->json($this->api('admin', 'GET', '/api/jobs/' . $id)), 'job', 'last_run', 'id'));
    }

    // --- Leak-Test ---------------------------------------------------------------------------------------------------

    public function testNoSecretOfTheJobAppearsInResponsesRunsOrAudit(): void
    {
        $secret = self::testSecret('query');
        $created = $this->api('admin', 'POST', '/api/jobs', [
            'name' => 'Geheim', 'type' => 'http', 'category_id' => 1, 'cron' => '*/5 * * * *', 'timezone' => 'Europe/Berlin',
            'request' => [
                'url' => 'https://hooks.example.org/x/' . self::testSecret('pfad') . '?key=' . $secret,
                'headers' => [['name' => 'Authorization', 'value' => 'Bearer ' . self::testSecret('header')]],
                'body' => self::testSecret('body'),
            ],
            'http' => ['method' => 'POST'],
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $id = self::dig($this->json($created), 'job', 'id');
        self::assertIsInt($id);
        $secrets = [$secret, self::testSecret('pfad'), self::testSecret('header'), self::testSecret('body')];

        $responses = [];
        foreach (['run', 'test'] as $kind) {
            $response = $this->api('admin', 'POST', '/api/jobs/' . $id . '/' . $kind);
            self::assertSame(202, $response->getStatusCode());
            $responses[] = $this->plain($response);
            $this->db()->execute("UPDATE runs SET status = 'ok'");
            foreach (['run', 'test'] as $again) {
                // 409/429-Antworten sind ebenfalls Ausgabestellen.
                $this->api('admin', 'POST', '/api/jobs/' . $id . '/' . $again);
                $this->db()->execute("UPDATE runs SET status = 'ok'");
            }
        }
        $this->db()->execute("UPDATE runs SET status = 'queued' WHERE id = (SELECT MAX(id) FROM runs)");
        $conflict = $this->api('admin', 'POST', '/api/jobs/' . $id . '/run');
        self::assertSame(409, $conflict->getStatusCode());
        $responses[] = $this->plain($conflict);
        $this->seedFinishedRuns($this->userId('admin'), 60, '2026-10-07T08:00:00+00:00');
        $this->db()->execute("UPDATE runs SET status = 'ok'");
        $limited = $this->api('admin', 'POST', '/api/jobs/' . $id . '/run');
        self::assertSame(429, $limited->getStatusCode());
        $responses[] = $this->plain($limited);

        self::assertNoSecret(implode("\n", $responses), $secrets, 'Antworten');
        $dump = json_encode([
            $this->db()->fetchAll('SELECT * FROM audit_log'),
            $this->db()->fetchAll('SELECT id, job_id, trigger, status, note, output FROM runs'),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertNoSecret($dump, $secrets, 'Audit-Log und Läufe');
    }

    // --- Der Weg bis zum Worker (H1 beim Übernehmen) ------------------------------------------------------------------------

    private function worker(FakeRunner $runner): SchedulerHarness
    {
        $harness = new SchedulerHarness($this->db());
        $harness->runners->register(JobType::Http, $runner);

        return $harness;
    }

    public function testEnqueuedRunsAreExecutedByTheWorkerAndTestRunsOfDisabledJobsToo(): void
    {
        $id = $this->jobIds['JA'];
        $this->db()->execute('UPDATE jobs SET is_enabled = 0, next_run_at = NULL WHERE id = :id', ['id' => $id]);
        $runId = $this->runIdOf($this->api('operator', 'POST', '/api/jobs/' . $id . '/test'));
        $runner = new FakeRunner(RunResult::ok('fertig'));
        $harness = $this->worker($runner);

        $harness->worker->work(static fn (): bool => false);

        self::assertCount(1, $runner->requests);
        self::assertSame($this->userId('operator'), $runner->requests[0]->startedBy);
        $detail = $this->json($this->api('observer', 'GET', '/api/runs/' . $runId));
        self::assertSame('ok', self::dig($detail, 'run', 'status'));
        self::assertSame('fertig', self::dig($detail, 'run', 'output'));
        // Danach ist der Job wieder frei für einen neuen manuellen Lauf/Testlauf.
        self::assertSame(202, $this->api('operator', 'POST', '/api/jobs/' . $id . '/test')->getStatusCode());
    }

    public function testRightsRevokedBetweenEnqueueAndPickupSkipTheRun(): void
    {
        $id = $this->jobIds['JA'];
        $runId = $this->runIdOf($this->api('operatorA', 'POST', '/api/jobs/' . $id . '/run'));
        $this->db()->execute("UPDATE users SET is_active = 0 WHERE username = 'operatorA'");
        $runner = new FakeRunner();
        $harness = $this->worker($runner);

        $harness->worker->work(static fn (): bool => false);

        self::assertSame([], $runner->requests);
        self::assertSame('skipped', self::dig($this->json($this->api('admin', 'GET', '/api/runs/' . $runId)), 'run', 'status'));
    }
}
