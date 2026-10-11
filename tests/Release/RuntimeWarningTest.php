<?php

declare(strict_types=1);

namespace Meridian\Tests\Release;

use Meridian\Database\Timestamp;
use Meridian\Notify\{ChannelStore, Message, Notifier};
use Meridian\Security\{SecretBox, SecretMasker};
use Meridian\Tests\Support\SchedulerHarness;
use PHPUnit\Framework\TestCase;

final class RuntimeWarningTest extends TestCase
{
    private SchedulerHarness $h;
    private Notifier $notifier;
    private int $job;

    protected function setUp(): void
    {
        $this->h = new SchedulerHarness();
        $this->job = $this->h->addJob();
        $this->h->db->execute('UPDATE jobs SET runtime_warn_percent = 200 WHERE id = :id', ['id' => $this->job]);
        $this->h->db->execute("INSERT INTO notification_channels (id, name, type, config_json, created_at, updated_at) VALUES (1, 'Test', 'ntfy', '{}', :at, :at)", ['at' => Timestamp::format($this->h->clock->now())]);
        $box = new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->notifier = new Notifier($this->h->db, $this->h->clock, new ChannelStore($this->h->db, $box, $this->h->clock), [], new SecretMasker());
    }

    private function completedRun(int $duration, string $trigger = 'schedule', string $status = 'ok', string $age = '-1 second'): int
    {
        $id = $this->h->addRun($this->job, $status, trigger: $trigger);
        $this->h->db->execute('UPDATE runs SET duration_ms = :duration, finished_at = :at WHERE id = :id', ['duration' => $duration, 'at' => Timestamp::format($this->h->clock->now()->modify($age)), 'id' => $id]);
        return $id;
    }

    public function testBaselineThresholdAndNoDuplicateNotification(): void
    {
        foreach ([1000, 1100, 900, 1000] as $ms) {
            $this->completedRun($ms);
        }
        self::assertSame(0, $this->notifier->evaluateRuntime());
        $this->completedRun(1000);
        $this->completedRun(1999);
        self::assertSame(0, $this->notifier->evaluateRuntime());
        $slow = $this->completedRun(2000);
        self::assertSame(1, $this->notifier->evaluateRuntime());
        self::assertSame(0, $this->notifier->evaluateRuntime(), 'Do not notify twice');
        $row = $this->h->db->fetchOne('SELECT kind, run_id, detail FROM notification_outbox');
        self::assertSame('slow', $row['kind']);
        self::assertSame($slow, $row['run_id']);
        self::assertStringContainsString('üblich: 1000 ms', $row['detail']);
        $english = new Message('slow', 'Example', 'ok', '2026-10-07', $row['detail'], 0, language: 'en');
        self::assertStringContainsString('longer than usual', $english->subject());
        self::assertStringContainsString('usual: 1000 ms', $english->text());
    }

    public function testTestRunsFailuresAndOldRunsDoNotWarnOrPolluteBaseline(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->completedRun(1000, age: '-2 hours');
        }
        $this->completedRun(50000, 'test');
        $this->completedRun(50000, status: 'failed');
        $this->completedRun(50000, age: '-2 hours');
        self::assertSame(0, $this->notifier->evaluateRuntime());
        $this->completedRun(3000);
        self::assertSame(1, $this->notifier->evaluateRuntime());
    }

    public function testOverlappingRunFinishingLateIsStillEvaluated(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->completedRun(1000, age: '-2 minutes');
        }
        $late = $this->h->addRun($this->job, 'running');
        $this->completedRun(1000);
        self::assertSame(0, $this->notifier->evaluateRuntime());
        $this->h->db->execute("UPDATE runs SET status = 'ok', duration_ms = 4000, finished_at = :at WHERE id = :id", ['at' => Timestamp::format($this->h->clock->now()), 'id' => $late]);
        self::assertSame(1, $this->notifier->evaluateRuntime());
    }

    public function testSelectedAndDisabledChannelsAreRespected(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->completedRun(1000);
        }
        $this->h->db->execute("UPDATE jobs SET notify_channels = 'selected' WHERE id = :id", ['id' => $this->job]);
        $this->completedRun(3000);
        self::assertSame(0, $this->notifier->evaluateRuntime());
        $this->h->db->execute('INSERT INTO job_channels (job_id, channel_id) VALUES (:id, 1)', ['id' => $this->job]);
        $this->completedRun(4000);
        self::assertSame(1, $this->notifier->evaluateRuntime());
        $this->h->db->execute('UPDATE notification_channels SET is_enabled = 0');
        $this->completedRun(5000);
        self::assertSame(0, $this->notifier->evaluateRuntime());
    }
}
