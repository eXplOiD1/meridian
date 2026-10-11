<?php

declare(strict_types=1);

namespace Meridian\Runner\Shell\Docker;

/**
 * `MERIDIAN_DOCKER_PROXY` ist kein erlaubter Unix-Socket (docs/decisions/0004 E10). Feste Meldung ohne den Wert.
 */
final class InvalidDockerProxy extends \InvalidArgumentException
{
    public const MESSAGE = 'MERIDIAN_DOCKER_PROXY: Nur ein Unix-Socket ist erlaubt (unix:///pfad/zum/docker.sock), siehe docs/decisions/0004 E10.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
