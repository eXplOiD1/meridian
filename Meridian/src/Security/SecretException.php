<?php

declare(strict_types=1);

namespace Meridian\Security;

/**
 * Fehler beim Umgang mit Geheimnissen. Die Meldung enthält nie den geheimen Wert selbst.
 */
final class SecretException extends \RuntimeException
{
}
