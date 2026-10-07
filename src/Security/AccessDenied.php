<?php

declare(strict_types=1);

namespace Meridian\Security;

final class AccessDenied extends \RuntimeException
{
    public function __construct(public readonly Permission $permission)
    {
        parent::__construct('Dafür fehlt das Recht „' . $permission->value . '“.');
    }
}
