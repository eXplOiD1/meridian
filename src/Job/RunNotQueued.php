<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Ein manueller Lauf oder Testlauf wurde nicht eingereiht. Die Meldung ist ein fester Text ohne Jobdaten.
 */
final class RunNotQueued extends \RuntimeException
{
    public function __construct(public readonly RunRefusal $refusal)
    {
        parent::__construct(match ($refusal) {
            RunRefusal::JobDisabled => 'Der Job ist deaktiviert. Zuerst aktivieren oder einen Testlauf starten.',
            RunRefusal::AlreadyOpen => 'Für diesen Job wartet oder läuft bereits ein manueller Lauf oder Testlauf. Das Ergebnis abwarten.',
            RunRefusal::RateLimited => 'Zu viele manuelle Läufe und Testläufe in der letzten Stunde (höchstens ' . RunService::MAX_PER_HOUR . '). Später erneut versuchen.',
        });
    }
}
