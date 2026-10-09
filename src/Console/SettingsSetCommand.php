<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Security\SecretMasker;
use Meridian\Settings\InvalidSetting;
use Meridian\Settings\SettingKey;
use Meridian\Settings\SettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Setzt eine globale Einstellung (docs/decisions/0003, E10):
 *
 *   settings:set http.max_timeout_seconds 600
 *   settings:set http.display_path hidden          verschärft gespeicherte Anzeige-URLs aller HTTP-Jobs
 *   settings:set http.response_storage --reset      zurück auf den Standard
 *
 * Dieselbe Prüfung, Transaktion und derselbe Audit-Eintrag `settings.changed` wie `PUT /api/settings/{key}`
 * ({@see SettingsService::change()}), hier ohne Benutzer. Eine Zahl wird nur für das Zeitlimit-Maximum als Zahl
 * gelesen; alles andere bleibt Text und wird von derselben Prüfung abgelehnt oder angenommen. Eingaben erscheinen
 * nie in Fehlermeldungen. Die Vertrauensgrenze ist der Betriebssystem-Benutzer (mer-security §4).
 */
#[AsCommand(name: 'settings:set', description: 'Setzt eine globale Einstellung oder setzt sie auf den Standard zurück')]
final class SettingsSetCommand extends Command
{
    public function __construct(
        private readonly SettingsService $service,
        private readonly SecretMasker $masker = new SecretMasker(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Schlüssel, z. B. http.max_timeout_seconds')
            ->addArgument('value', InputArgument::OPTIONAL, 'Neuer Wert, z. B. 600, on, hidden')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Auf den Standardwert zurücksetzen');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = SettingKey::tryFrom(SettingsGetCommand::text($input->getArgument('key')));
        if ($key === null) {
            return $this->fail($output, SettingsGetCommand::unknownKey());
        }
        $hasValue = $input->getArgument('value') !== null;
        $reset = $input->getOption('reset') === true;
        if ($reset === $hasValue) {
            return $this->fail($output, 'Genau eines angeben: einen neuen Wert oder --reset.');
        }

        try {
            $change = $this->service->change($key, $reset ? null : self::typed($key, SettingsGetCommand::text($input->getArgument('value'))), null);
        } catch (InvalidSetting $e) {
            return $this->fail($output, $e->getMessage());
        }

        $output->writeln($this->masker->mask(
            'Einstellung ' . $key->value . ': ' . $change->old . ' → ' . $change->new . ($reset ? ' (Standard)' : '') . '.',
        ), OutputInterface::OUTPUT_RAW);
        if ($change->tightenedJobs > 0) {
            $output->writeln('Anzeige-URL bei ' . $change->tightenedJobs . ' HTTP-Job(s) verschärft (Pfad und Query verborgen).');
        }

        return self::SUCCESS;
    }

    /**
     * Die Befehlszeile kennt nur Text: Für das Zeitlimit-Maximum wird eine Ziffernfolge ohne führende Null zur Zahl,
     * sonst bleibt der Text, und {@see \Meridian\Settings\Settings::validate()} entscheidet wie bei der API.
     */
    private static function typed(SettingKey $key, string $value): int|string
    {
        if ($key === SettingKey::HttpMaxTimeout && preg_match('/^(0|[1-9][0-9]{0,8})$/D', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . $message . '</error>');

        return self::INVALID;
    }
}
