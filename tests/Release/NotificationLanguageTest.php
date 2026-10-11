<?php

declare(strict_types=1);
namespace Meridian\Tests\Unit\Notify;
use Meridian\Tests\Support\ApiHarness;
use Meridian\Notify\{ChannelStore, Message, InvalidChannel, ChannelValidator};
use Meridian\Runner\Http\HttpRunner;
use PHPUnit\Framework\TestCase;
final class NotificationLanguageTest extends TestCase {
    use ApiHarness;
    public function testSavedAccountLanguageAndExplicitOverride(): void {
        $this->users()->create('admin', 'Admin', self::PASSWORD, 'admin');
        $login=$this->login('admin');
        $cookies=[self::COOKIE=>$this->sessionCookie($login)];
        $headers=['Origin'=>'https://meridian.test','X-CSRF-Token'=>$this->csrfOf($login)];
        self::assertSame(200,$this->call('PUT','/api/auth/language',['language'=>'en'],$headers,$cookies)->getStatusCode());
        self::assertSame('en',$this->db()->fetchOne('SELECT language FROM users WHERE username = :name',['name'=>'admin'])['language']);
        self::assertSame('en', $this->json($this->call('GET', '/api/auth/me', [], [], $cookies))['language']);
        $body=['name'=>'Language test','type'=>'ntfy','server'=>'https://ntfy.sh','topic'=>'meridian-language-test','priority'=>3];
        $response=$this->call('POST','/api/notifications/channels',$body,$headers,$cookies);
        self::assertSame(201,$response->getStatusCode(),$response->getContent());
        $channel=$this->json($response)['channel'];
        self::assertSame('ui',$channel['config']['language']);
        $store=new ChannelStore($this->db(),$this->box(),$this->clock());
        $record=$store->find($channel['id']);
        self::assertSame('en',$store->languageFor($record));
        self::assertSame(200,$this->call('PUT','/api/auth/language',['language'=>'de'],$headers,$cookies)->getStatusCode());
        self::assertSame('de',$store->languageFor($record));
        $body['language']='en';
        $updated=$this->call('PUT','/api/notifications/channels/'.$record->id,$body,$headers,$cookies);
        self::assertSame(200,$updated->getStatusCode(),$updated->getContent());
        self::assertSame('en',$store->languageFor($store->find($record->id)));
        $body['language']='fr';
        self::assertSame(422,$this->call('PUT','/api/notifications/channels/'.$record->id,$body,$headers,$cookies)->getStatusCode());
        self::assertSame('en',$store->languageFor($store->find($record->id)));
        self::assertSame(403,$this->call('PUT','/api/auth/language',['language'=>'en'],[], $cookies)->getStatusCode());
        self::assertSame('de',$this->db()->fetchOne('SELECT language FROM users WHERE username = :name',['name'=>'admin'])['language']);
    }
    public function testEnglishAndGermanMessages(): void {
        foreach (['failure','recovery','monitor_down','monitor_up','disabled','tls_expiry','test'] as $kind) {
            $en=new Message($kind,'Example','timeout','2026-10-11T12:00:00+00:00',HttpRunner::NOTE_TLS,3,null,'en');
            $de=new Message($kind,'Example','timeout','2026-10-11T12:00:00+00:00',HttpRunner::NOTE_TLS,3);
            self::assertNotSame($de->subject(),$en->subject());
            self::assertNotSame($de->text(),$en->text());
            if ($kind!=='test') {
                self::assertStringContainsString('TLS verification failed',$en->text());
                self::assertStringContainsString(HttpRunner::NOTE_TLS,$de->text());
            }
        }
    }
    public function testEnglishDiagnosticsAndCustomContent(): void {
        self::assertSame('No reply to the ping.', \Meridian\Notify\MessageDetail::english(\Meridian\Monitor\Probe\NetworkProbe::NOTE_PING));
        self::assertSame('Connection to the Docker proxy was lost.', \Meridian\Notify\MessageDetail::english(\Meridian\Runner\Shell\ExecFailed::CONNECTION_LOST));
        self::assertSame('Exited with code 2.', \Meridian\Notify\MessageDetail::english('Beendet mit Exit-Code 2.'));
        self::assertSame('Unexpected status code 503 (expected: 200-299).', \Meridian\Notify\MessageDetail::english('Unerwarteter Statuscode 503 (erwartet: 200-299).'));
        self::assertSame('No heartbeat received yet (expected about every 5 min).', \Meridian\Notify\MessageDetail::english('Es kam noch kein Lebenszeichen (erwartet etwa alle 5 Min).'));
        self::assertSame('My own detail', \Meridian\Notify\MessageDetail::english('My own detail'));
    }
}
