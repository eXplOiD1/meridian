<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Interpreter eines Shell-Jobs (Allowlist, docs/decisions/0004 E12). Das Skript wird über stdin gelesen; der
 * Interpreter ist nie frei wählbar.
 */
enum ShellInterpreter: string
{
    case Sh = 'sh';
    case Bash = 'bash';
}
