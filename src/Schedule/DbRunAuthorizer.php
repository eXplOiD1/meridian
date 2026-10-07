<?php

declare(strict_types=1);

namespace Meridian\Schedule;

use Meridian\Database\Connection;
use Meridian\Security\AccessControl;
use Meridian\Security\Permission;
use Meridian\User\UserRepository;

/**
 * H1 aus der Datenbank: `jobs.run` für die gespeicherte Kategorie des Jobs, mit den Rechten, wie sie jetzt
 * gelten. Ein deaktivierter oder gelöschter Benutzer hat keine Rechte (`grantsFor()` lädt nur aktive).
 */
final class DbRunAuthorizer implements RunAuthorizer
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly AccessControl $access = new AccessControl(),
    ) {
    }

    #[\Override]
    public function mayStart(int $userId, int $jobId): bool
    {
        try {
            $job = $this->db->fetchOne(
                'SELECT j.id, c.name AS category FROM jobs j LEFT JOIN categories c ON c.id = j.category_id WHERE j.id = :id',
                ['id' => $jobId],
            );
            if ($job === null) {
                return false;
            }
            $category = $job['category'] ?? null;
            if ($category !== null && !is_string($category)) {
                return false;
            }

            return $this->access->can($this->users->grantsFor($userId), Permission::RunJobs, $category);
        } catch (\Throwable) {
            // Datenbankfehler: nicht starten (Standard ist verboten).
            return false;
        }
    }
}
