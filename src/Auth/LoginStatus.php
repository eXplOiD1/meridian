<?php

declare(strict_types=1);

namespace Meridian\Auth;

enum LoginStatus
{
    case Success;
    case Invalid;
    case Locked;
}
