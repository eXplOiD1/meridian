<?php

declare(strict_types=1);

namespace Meridian\Shell;

use Meridian\Runner\Shell\ShellTarget;

/**
 * Eine geprüfte Eingabe für eine neue Freigabe (Ergebnis von {@see ShellTargetStore::validate()}).
 */
final readonly class ShellTargetInput
{
    /**
     * @param list<string> $users
     */
    public function __construct(
        public ShellTarget $target,
        public ?int $categoryId,
        public array $users,
        public ?string $defaultUser,
        public string $note,
    ) {
    }
}
