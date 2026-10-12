<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Gespeicherte `config_json` eines Jobs ist unlesbar. Feste Meldung ohne Inhalt.
 */
final class InvalidJobConfig extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Gespeicherte Job-Konfiguration nicht lesbar. Die Anfrage im Job neu eingeben und speichern.');
    }
}
