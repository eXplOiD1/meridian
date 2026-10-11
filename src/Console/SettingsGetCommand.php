<?php

declare(strict_types=1);

namespace Meridian\Console;

use Meridian\Security\SecretMasker;
use Meridian\Settings\SettingEntry;
use Meridian\Settings\SettingKey;
use Meridian\Settings\SettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Zeigt globale Einstellungen (docs/decisions/0003, E10):
 *
 *   settings:get                              alle Schlüssel der Allowlist
 *   settings:get http.max_timeout_seconds     nur diesen
 *
 * Zeile: `<schlüssel> = <wert> (Standard: <standard>; Quelle: gespeichert|Standard)`. Nur lesen, kein Audit.
 * Die Vertrauensgrenze ist der Betriebssystem-Benutzer (mer-security §4).
 */
#[AsCommand(name: 'settings:get', description: 'Zeigt die globalen Einstellungen mit Wert, Standard und Quelle')]
final class SettingsGetCommand extends Command
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
        $this->addArgument('key', InputArgument::OPTIONAL, 'Schlüssel, z. B. http.max_timeout_seconds; ohne Angabe alle');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = null;
        if ($input->getArgument('key') !== null) {
            $key = SettingKey::tryFrom(self::text($input->getArgument('key')));
            if ($key === null) {
                $output->writeln('<error>' . self::unknownKey() . '</error>');

                return self::INVALID;
            }
        }

        foreach ($this->service->entries() as $entry) {
            if ($key === null || $entry->key === $key) {
                $output->writeln($this->line($entry), OutputInterface::OUTPUT_RAW);
            }
        }

        return self::SUCCESS;
    }

    /** Argument als Text; alles andere wird zu '' (kein Schlüssel der Allowlist). */
    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function unknownKey(): string
    {
        return 'Einstellung unbekannt. Erlaubt sind ' . implode(', ', array_map(static fn (SettingKey $k): string => $k->value, SettingKey::cases())) . '.';
    }

    private function line(SettingEntry $entry): string
    {
        return $this->masker->mask(
            $entry->key->value . ' = ' . $entry->value
            . ' (Standard: ' . $entry->key->default() . '; Quelle: ' . ($entry->stored ? 'gespeichert' : 'Standard') . ')',
        );
    }
}
