<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell;

/**
 * Warum ein Ausführungsort für einen Job nicht erlaubt ist. Die Meldungen sind fest und nennen nie Namen von
 * Containern, die der Benutzer nicht schon kennt.
 */
enum ShellTargetRefusal: string
{
    case TargetNotAllowed = 'target';
    case UserNotAllowed = 'user';
    case Infrastructure = 'infrastructure';

    public function message(): string
    {
        return match ($this) {
            self::TargetNotAllowed => 'Ausführungsort unbekannt oder für diese Kategorie nicht freigegeben.',
            self::UserNotAllowed => 'Benutzer für diesen Ausführungsort nicht freigegeben.',
            self::Infrastructure => InfrastructureContainers::MESSAGE,
        };
    }

    /** Meldung für den Lauf (Worker): sagt, wie es behoben wird (ADR 0004 E4). */
    public function runNote(): string
    {
        return match ($this) {
            self::TargetNotAllowed => 'Ausführungsort nicht (mehr) freigegeben: Ein Admin kann ihn unter Einstellungen → Ausführungsorte freigeben.',
            self::UserNotAllowed => 'Benutzer für den Ausführungsort nicht (mehr) freigegeben: Ein Admin kann ihn unter Einstellungen → Ausführungsorte erlauben.',
            self::Infrastructure => 'Meridians eigene Container sind als Ausführungsort gesperrt: Job auf einen anderen Container umstellen.',
        };
    }
}
