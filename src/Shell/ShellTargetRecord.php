<?php

declare(strict_types=1);

namespace Meridian\Shell;

use Meridian\Runner\Shell\ShellRules;
use Meridian\Runner\Shell\ShellTarget;

/**
 * Eine gespeicherte Freigabe mit Kategorie- und Benutzername für Anzeige und Audit. Enthält keine Geheimnisse.
 */
final readonly class ShellTargetRecord
{
    /**
     * @param list<string> $users
     */
    public function __construct(
        public int $id,
        public ShellTarget $target,
        public ?int $categoryId,
        public ?string $categoryName,
        public array $users,
        public ?string $defaultUser,
        public string $note,
        public string $createdAt,
        public ?string $createdBy,
    ) {
    }

    public function allowsRoot(): bool
    {
        foreach ($this->users as $user) {
            if (ShellRules::isRootUser($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text für den Audit-Eintrag: `docker nextcloud (Kategorie NAS; Benutzer www-data, Standard www-data)` bzw.
     * `host default (global)`.
     */
    public function describe(): string
    {
        $scope = $this->categoryId === null ? 'global' : 'Kategorie ' . ($this->categoryName ?? '#' . $this->categoryId);
        $users = $this->users === [] ? '' : '; Benutzer ' . implode(', ', $this->users) . ', Standard ' . ($this->defaultUser ?? '-');

        return $this->target->describe() . ' (' . $scope . $users . ')';
    }
}
