<?php

declare(strict_types=1);

namespace Meridian\Tests\Release;

use Meridian\Database\Timestamp;
use Meridian\Notify\{ChannelRecord, ChannelStore, Message, Notifier, Recipients, Sender};
use Meridian\Security\{Sealed, SecretBox, SecretMasker};
use Meridian\Tests\Support\{ApiHarness, SchedulerHarness};
use PHPUnit\Framework\TestCase;

final class NotificationRecipientsTest extends TestCase
{
    use ApiHarness;

    public function testApiPersistsRecipientsValidatesThemAndRequiresPermission(): void
    {
        $this->users()->create('admin', 'Admin', self::PASSWORD, 'admin');
        $this->users()->create('reader', 'Reader', self::PASSWORD, 'Beobachter');
        $login = $this->login('admin');
        $cookies = [self::COOKIE => $this->sessionCookie($login)];
        $headers = ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($login)];
        $created = $this->call('POST', '/api/categories/manage', ['name' => 'Mail'], $headers, $cookies);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $category = $this->json($created)['category'];
        self::assertSame([], $category['recipients']);
        $url = '/api/categories/manage/' . $category['id'] . '/recipients';
        self::assertSame(403, $this->call('PUT', $url, ['recipients' => []], [], $cookies)->getStatusCode());
        $reader = $this->login('reader');
        self::assertSame(403, $this->call('PUT', $url, ['recipients' => []], ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($reader)], [self::COOKIE => $this->sessionCookie($reader)])->getStatusCode());
        $saved = $this->call('PUT', $url, ['recipients' => ['category@example.org', 'category@example.org']], $headers, $cookies);
        self::assertSame(200, $saved->getStatusCode(), $saved->getContent());
        self::assertSame(['category@example.org'], $this->json($saved)['category']['recipients']);
        self::assertSame(['category@example.org'], $this->json($this->call('GET', '/api/categories/manage', [], [], $cookies))['categories'][0]['recipients']);
        self::assertSame(404, $this->call('PUT', '/api/categories/manage/999/recipients', ['recipients' => []], $headers, $cookies)->getStatusCode());

        $input = ['name' => 'Mail job', 'type' => 'http', 'category_id' => $category['id'], 'cron' => '*/5 * * * *', 'timezone' => 'UTC', 'request' => ['url' => 'https://example.org/test'], 'notify' => ['recipients' => ['job@example.org']]];
        $created = $this->call('POST', '/api/jobs', $input, $headers, $cookies);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $job = $this->json($created)['job'];
        self::assertSame(['job@example.org'], $job['notify']['recipients']);
        $jobUrl = '/api/jobs/' . $job['id'];
        foreach ([null, 'bad', ['invalid'], ["a@example.org\r\nBcc: b@example.org"], [42], array_fill(0, 11, 'a@example.org'), ['name' => 'a@example.org']] as $invalid) {
            self::assertSame(422, $this->call('PUT', $url, ['recipients' => $invalid], $headers, $cookies)->getStatusCode());
            $response = $this->call('PUT', $jobUrl, ['notify' => ['recipients' => $invalid]], $headers, $cookies);
            self::assertSame(422, $response->getStatusCode());
            self::assertArrayHasKey('notify.recipients', $this->json($response)['fields']);
        }
        $unchanged = $this->call('PUT', $jobUrl, ['notify' => ['on_recovery' => true]], $headers, $cookies);
        self::assertSame(200, $unchanged->getStatusCode());
        self::assertSame(['job@example.org'], $this->json($unchanged)['job']['notify']['recipients']);
        $reset = $this->call('PUT', $jobUrl, ['notify' => ['recipients' => []]], $headers, $cookies);
        self::assertSame(200, $reset->getStatusCode());
        self::assertSame([], $this->json($reset)['job']['notify']['recipients']);
        self::assertSame([], $this->json($this->call('GET', $jobUrl, [], [], $cookies))['job']['notify']['recipients']);
        self::assertSame(200, $this->call('PUT', $url, ['recipients' => []], $headers, $cookies)->getStatusCode());
    }

    public function testDeliveryUsesJobThenCategoryThenChannelAndKeepsQueuedOverrides(): void
    {
        $h = new SchedulerHarness();
        $h->db->execute("INSERT INTO categories (id, name) VALUES (1, 'Mail')");
        $job = $h->addJob(['category_id' => 1]);
        $h->db->execute('UPDATE jobs SET notify_on_failure = 1 WHERE id = :id', ['id' => $job]);
        $at = Timestamp::format($h->clock->now());
        foreach (['email', 'ntfy'] as $type) {
            $h->db->execute('INSERT INTO notification_channels (name, type, config_json, created_at, updated_at) VALUES (:type, :type, :config, :at, :at)', ['type' => $type, 'config' => json_encode(['to' => ['channel@example.org']], JSON_THROW_ON_ERROR), 'at' => $at]);
        }
        $sender = new class implements Sender {
            /** @var list<ChannelRecord> */
            public array $received = [];
            public function send(ChannelRecord $channel, ?Sealed $secret, Message $message): void
            {
                $this->received[] = $channel;
            }
        };
        $store = new ChannelStore($h->db, new SecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), $h->clock);
        $notifier = new Notifier($h->db, $h->clock, $store, ['email' => $sender, 'ntfy' => $sender], new SecretMasker());
        foreach ([['category' => [], 'job' => [], 'expected' => ['channel@example.org']], ['category' => ['category@example.org'], 'job' => [], 'expected' => ['category@example.org']], ['category' => ['category@example.org'], 'job' => ['job@example.org'], 'expected' => ['job@example.org']]] as $case) {
            $h->db->execute('UPDATE categories SET notify_recipients = :r WHERE id = 1', ['r' => json_encode($case['category'], JSON_THROW_ON_ERROR)]);
            $h->db->execute('UPDATE jobs SET notify_recipients = :r WHERE id = :id', ['r' => json_encode($case['job'], JSON_THROW_ON_ERROR), 'id' => $job]);
            $h->db->execute('DELETE FROM job_notify_state');
            $h->db->execute('DELETE FROM runs');
            $run = $h->addRun($job, 'failed');
            $h->db->execute('UPDATE runs SET finished_at = :at WHERE id = :id', ['at' => $at, 'id' => $run]);
            self::assertSame(2, $notifier->evaluate());
            $h->db->execute("UPDATE jobs SET notify_recipients = '[]' WHERE id = :id", ['id' => $job]);
            $h->db->execute("UPDATE categories SET notify_recipients = '[]' WHERE id = 1");
            $sender->received = [];
            self::assertSame(['sent' => 2, 'retry' => 0, 'failed' => 0], $notifier->deliver());
            self::assertSame($case['expected'], $sender->received[0]->config['to']);
            self::assertSame(['channel@example.org'], $sender->received[1]->config['to'], 'Non-email channels are unchanged');
            self::assertSame(['channel@example.org'], $store->find(1)->config['to'], 'Channel defaults remain unchanged');
        }
    }

    public function testRecipientListsAllowTenAddressesAndRemoveDuplicates(): void
    {
        $addresses = array_map(static fn (int $n): string => 'test' . $n . '@example.org', range(1, 10));
        self::assertSame($addresses, Recipients::validate($addresses, 'recipients'));
        self::assertSame(['test@example.org'], Recipients::validate(['test@example.org', 'test@example.org'], 'recipients'));
        self::assertSame([], Recipients::decode('[]'));
    }

    public function testMonitorChannelSelectionPersistsAndOnlySelectedChannelsReceiveCategoryMail(): void
    {
        $this->users()->create('admin', 'Admin', self::PASSWORD, 'admin');
        $login = $this->login('admin');
        $cookies = [self::COOKIE => $this->sessionCookie($login)];
        $headers = ['Origin' => 'https://meridian.test', 'X-CSRF-Token' => $this->csrfOf($login)];
        $at = Timestamp::format($this->clock()->now());
        $this->db()->execute("INSERT INTO categories (id, name, notify_recipients) VALUES (1, 'Mail', '[\"category@example.org\"]')");
        foreach ([1, 2] as $id) {
            $this->db()->execute("INSERT INTO notification_channels (id, name, type, config_json, created_at, updated_at) VALUES (:id, :name, 'email', '{\"to\":[\"channel@example.org\"]}', :at, :at)", ['id' => $id, 'name' => 'Mail ' . $id, 'at' => $at]);
        }
        $created = $this->call('POST', '/api/monitors', ['name' => 'Selected monitor', 'category_id' => 1, 'interval_seconds' => 60, 'grace_seconds' => 0, 'notify_channels' => 'selected', 'channel_ids' => [2, 2]], $headers, $cookies);
        self::assertSame(201, $created->getStatusCode(), $created->getContent());
        $monitor = $this->json($created)['monitor'];
        self::assertSame('selected', $monitor['notify_channels']);
        self::assertSame([2], $monitor['channel_ids']);
        $url = '/api/monitors/' . $monitor['id'];
        self::assertSame(403, $this->call('PUT', $url, ['notify_channels' => 'none'], [], $cookies)->getStatusCode());
        foreach ([null, 'bad', [0], [true], ['2'], [999], array_fill(0, 21, 2)] as $invalid) {
            self::assertSame(422, $this->call('PUT', $url, ['notify_channels' => 'selected', 'channel_ids' => $invalid], $headers, $cookies)->getStatusCode());
        }
        $updated = $this->call('PUT', $url, ['name' => 'Renamed monitor'], $headers, $cookies);
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame([2], $this->json($updated)['monitor']['channel_ids']);
        self::assertSame([2], $this->json($this->call('GET', '/api/monitors', [], [], $cookies))['monitors'][0]['channel_ids']);
        $this->clock()->advance(61);
        $notifier = new Notifier($this->db(), $this->clock(), new ChannelStore($this->db(), $this->box(), $this->clock()), [], new SecretMasker());
        self::assertSame(1, $notifier->evaluateMonitors());
        $queued = $this->db()->fetchOne('SELECT channel_id, recipients FROM notification_outbox');
        self::assertSame(2, $queued['channel_id']);
        self::assertSame('["category@example.org"]', $queued['recipients']);
        foreach (['all', 'none'] as $mode) {
            $updated = $this->call('PUT', $url, ['notify_channels' => $mode], $headers, $cookies);
            self::assertSame(200, $updated->getStatusCode());
            self::assertSame($mode, $this->json($updated)['monitor']['notify_channels']);
            self::assertSame([], $this->json($updated)['monitor']['channel_ids']);
        }
    }

    public function testMigrationPreservesExistingDataAndPendingNotifications(): void
    {
        $db = \Meridian\Database\Connection::inMemory();
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') as $file) {
            if (basename($file) === '0029_notification_recipients.sql') {
                break;
            }
            $db->executeMigrationScript(file_get_contents($file));
        }
        $h = new SchedulerHarness($db);
        $db->execute("INSERT INTO categories (id, name) VALUES (1, 'Existing')");
        $job = $h->addJob(['category_id' => 1]);
        $at = Timestamp::format($h->clock->now());
        $db->execute("INSERT INTO notification_channels (id, name, type, config_json, created_at, updated_at) VALUES (1, 'Mail', 'email', '{}', :at, :at)", ['at' => $at]);
        $db->execute("INSERT INTO notification_outbox (kind, channel_id, job_id, job_name, occurred_at, next_attempt_at, created_at) VALUES ('failure', 1, :job, 'Existing', :at, :at, :at)", ['job' => $job, 'at' => $at]);
        $before = $db->fetchOne('SELECT * FROM notification_outbox');
        $db->executeMigrationScript(file_get_contents(dirname(__DIR__, 2) . '/migrations/0029_notification_recipients.sql'));
        self::assertSame($before + ['recipients' => null], $db->fetchOne('SELECT * FROM notification_outbox'));
        self::assertSame('[]', $db->fetchOne('SELECT notify_recipients FROM jobs')['notify_recipients']);
        self::assertSame('[]', $db->fetchOne('SELECT notify_recipients FROM categories')['notify_recipients']);
        self::assertSame([], $db->fetchAll('PRAGMA foreign_key_check'));
    }
}
