<?php

declare(strict_types=1);

namespace Meridian\Runner;

use Meridian\Security\SecretMasker;

/**
 * Führt einen Lauf aus (HTTP ab Phase 3, Shell ab Phase 4). Ein Runner schreibt nichts in die Datenbank;
 * den Laufzustand speichert der Worker. Während des Laufs ruft er regelmäßig {@see Heartbeat::beat()} auf.
 */
interface Runner
{
    /**
     * @param SecretMasker $masker gehört nur diesem Lauf (H4): Der Runner registriert darin jeden entschlüsselten
     *                             Bestandteil sofort nach dem Entschlüsseln; der Worker maskiert Ausgabe und Notiz
     *                             mit genau diesem Masker und verwirft ihn danach.
     * @param LiveLog      $live   nimmt nur mit `$masker` maskierte Stücke an (erst maskieren, dann senden); der
     *                             Worker leert es nach dem Lauf
     */
    public function run(RunRequest $request, Heartbeat $heartbeat, SecretMasker $masker, LiveLog $live): RunResult;
}
