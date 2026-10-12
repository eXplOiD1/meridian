<?php

// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary

declare(strict_types=1);

namespace Meridian\Runner\Shell\Agent;

/**
 * Verstoß gegen das Agent-Protokoll (ADR 0004 §5.6, ADR 0014). Die Meldung ist immer ein fester Text ohne Inhalte der
 * Rahmen: weder Skript noch Umgebungswerte tauchen je in einer Ausnahme, einem Journal-Eintrag oder einer Antwort auf.
 */
final class ProtocolError extends \RuntimeException
{
    public static function because(string $fixedText): self
    {
        return new self($fixedText);
    }
}
