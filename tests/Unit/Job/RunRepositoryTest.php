<?php

declare(strict_types=1);

namespace Meridian\Tests\Unit\Job;

use Meridian\Job\CategoryRepository;
use Meridian\Job\RunRepository;
use Meridian\Schedule\RunStatus;
use Meridian\Security\CategoryScope;
use Meridian\Tests\Support\JobHarness;
use PHPUnit\Framework\TestCase;

final class RunRepositoryTest extends TestCase
{
    use JobHarness;

    private int $jobA = 0;
    private int $jobB = 0;
    private int $jobN = 0;

    protected function setUp(): void
    {
        $this->setUpJobDatabase();
        $this->jobA = $this->jobs()->insert($this->draft(self::input(['name' => 'A', 'category_id' => 1])), 1);
        $this->jobB = $this->jobs()->insert($this->draft(self::input(['name' => 'B', 'category_id' => 2])), 1);
        $this->jobN = $this->jobs()->insert($this->draft(self::input(['name' => 'N', 'category_id' => null])), 1);
    }

    protected function tearDown(): void
    {
        $this->tearDownJobDatabase();
    }

    private function addRun(int $jobId, string $status = 'ok', string $output = 'Ausgabe', string $trigger = 'schedule', ?int $startedBy = null): int
    {
        $this->db()->execute(
            "INSERT INTO runs (job_id, trigger, status, scheduled_for, started_at, finished_at, duration_ms, http_status, output, note, worker, heartbeat_at, started_by)
             VALUES (:job, :trigger, :status, '2026-10-07T07:59:00+00:00', '2026-10-07T08:00:00+00:00', '2026-10-07T08:00:02+00:00', 2000, 200, :output, 'Notiz', 'WORKER-GEHEIM-ID', '2026-10-07T08:00:01+00:00', :by)",
            ['job' => $jobId, 'trigger' => $trigger, 'status' => $status, 'output' => $output, 'by' => $startedBy],
        );

        return $this->db()->lastInsertId();
    }

    public function testRunsAreVisibleExactlyWhenTheirJobIs(): void
    {
        $runs = ['A' => $this->addRun($this->jobA), 'B' => $this->addRun($this->jobB), 'N' => $this->addRun($this->jobN)];
        $categoryOf = ['A' => 'A', 'B' => 'B', 'N' => null];
        $jobOf = ['A' => $this->jobA, 'B' => $this->jobB, 'N' => $this->jobN];
        $repo = new RunRepository($this->db());
        $scopes = [CategoryScope::everything(), CategoryScope::nothing(), CategoryScope::only(['A']), CategoryScope::only(['B']), CategoryScope::only(['A', 'B']), CategoryScope::only(['Z'])];

        foreach ($scopes as $scope) {
            foreach ($runs as $label => $runId) {
                $expected = $scope->contains($categoryOf[$label]);
                self::assertSame($expected, $repo->findVisible($runId, $scope) !== null, 'Lauf ' . $label);
                self::assertSame($expected, $repo->history($jobOf[$label], $scope, 10, null, null) !== [], 'Verlauf ' . $label);
            }
        }
    }

    public function testRecordsCarryNoWorkerOrHeartbeatAndNoOutputInHistory(): void
    {
        $id = $this->addRun($this->jobA, startedBy: 7);
        $repo = new RunRepository($this->db());

        $detail = $repo->findVisible($id, CategoryScope::everything());
        $history = $repo->history($this->jobA, CategoryScope::everything(), 10, null, null);

        self::assertNotNull($detail);
        self::assertSame('Ausgabe', $detail->output);
        self::assertSame(7, $detail->startedById);
        self::assertSame('Bea', $detail->startedByName);
        self::assertSame('A', $detail->categoryName);
        self::assertNull($history[0]->output);
        foreach ([$detail, $history[0]] as $record) {
            self::assertStringNotContainsString('WORKER-GEHEIM-ID', (string) json_encode(get_object_vars($record)));
            self::assertArrayNotHasKey('worker', get_object_vars($record));
            self::assertArrayNotHasKey('heartbeatAt', get_object_vars($record));
        }
    }

    public function testHistoryPagesNewestFirstWithCursorAndStatusFilter(): void
    {
        $ids = [];
        foreach (['ok', 'failed', 'ok', 'timeout', 'ok'] as $status) {
            $ids[] = $this->addRun($this->jobA, $status);
        }
        $repo = new RunRepository($this->db());
        $all = CategoryScope::everything();

        $page = $repo->history($this->jobA, $all, 2, null, null);
        self::assertSame([$ids[4], $ids[3], $ids[2]], array_map(static fn ($r): int => $r->id, $page), 'limit + 1 zeigt: es gibt mehr');

        $next = $repo->history($this->jobA, $all, 2, $ids[3], null);
        self::assertSame([$ids[2], $ids[1], $ids[0]], array_map(static fn ($r): int => $r->id, $next));

        $failed = $repo->history($this->jobA, $all, 10, null, RunStatus::Timeout);
        self::assertSame([$ids[3]], array_map(static fn ($r): int => $r->id, $failed));
        self::assertCount(5, $repo->history($this->jobA, $all, 1000, null, null), 'Obergrenze 100 je Seite, hier nur 5 vorhanden');
    }

    public function testCategoriesAreCappedByTheSameScope(): void
    {
        $categories = new CategoryRepository($this->db());

        self::assertSame([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']], $categories->visible(CategoryScope::everything()));
        self::assertSame([['id' => 1, 'name' => 'A']], $categories->visible(CategoryScope::only(['A'])));
        self::assertSame([], $categories->visible(CategoryScope::nothing()));
        self::assertSame([], $categories->visible(CategoryScope::only(['Z'])));
        self::assertSame('B', $categories->nameOf(2));
        self::assertNull($categories->nameOf(99));
    }
}
