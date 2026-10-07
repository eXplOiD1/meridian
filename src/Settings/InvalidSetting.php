<?php

declare(strict_types=1);

namespace Meridian\Settings;

/**
 * Wert einer Einstellung ungültig. Die Meldung sagt, was erlaubt ist, und nennt nie den eingegebenen Wert.
 */
final class InvalidSetting extends \InvalidArgumentException
{
}
