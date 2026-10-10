<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Eine Freigabe eines Ausführungsorts (Zeile aus `shell_targets`): global (`categoryId` null) oder genau eine Kategorie.
 */
final readonly class ShellTargetGrant
{
    /**
     * @param list<string> $users erlaubte Benutzer im Container (bei `host` leer)
     */
    public function __construct(
        public int $id,
        public ShellTarget $target,
        public ?int $categoryId,
        public array $users,
        public ?string $defaultUser,
    ) {
    }

    /** Erlaubt diese Freigabe einen Benutzer mit Root-Rechten (`root`, UID 0)? Für Warnhinweise. */
    public function allowsRoot(): bool
    {
        foreach ($this->users as $user) {
            if (ShellRules::isRootUser($user)) {
                return true;
            }
        }

        return false;
    }
}
