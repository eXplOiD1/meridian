<?php
// Copyright (c) 2026 Alexander von Pidoll. All rights reserved.
// SPDX-License-Identifier: LicenseRef-Meridian-Proprietary
declare(strict_types=1);
namespace Meridian\Notify;

use Meridian\Database\Connection;
use Meridian\Job\Row;
use Meridian\Security\{AccessControl, PasswordHasher, Permission};
use Meridian\User\UserRepository;

/** Rights are checked again at delivery, including after a role or category change. */
final class BrowserPushAudience
{
    public function __construct(private readonly Connection $db) {}

    public function allows(int $user, Message $message): bool
    {
        $users = new UserRepository($this->db, new PasswordHasher());
        $grants = $users->grantsFor($user);
        $access = new AccessControl();
        if ($message->kind === 'test') {
            return $message->browserUserId === null
                ? $access->can($grants, Permission::ManageSettings)
                : $message->browserUserId === $user && (!$access->scope($grants, Permission::ViewJobs)->isEmpty() || $access->can($grants, Permission::ManageSettings));
        }
        if ($message->jobId !== null) {
            $row = $this->db->fetchOne('SELECT c.name AS category FROM jobs j LEFT JOIN categories c ON c.id = j.category_id WHERE j.id = :id', ['id' => $message->jobId]);
        } elseif ($message->monitorId !== null) {
            $row = $this->db->fetchOne('SELECT c.name AS category FROM monitors m LEFT JOIN categories c ON c.id = m.category_id WHERE m.id = :id', ['id' => $message->monitorId]);
        } else { return false; }
        return $row !== null && $access->can($grants, Permission::ViewJobs, Row::stringOrNull($row, 'category'));
    }
}
