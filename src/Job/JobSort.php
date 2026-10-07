<?php

declare(strict_types=1);

namespace Meridian\Job;

enum JobSort: string
{
    case Name = 'name';
    case NextRun = 'next_run';
}
