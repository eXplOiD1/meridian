<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Job;

enum JobSort: string
{
    case Name = 'name';
    case NextRun = 'next_run';
}
