<?php

declare(strict_types=1);

namespace Meridian\Http;

use Meridian\Auth\AuditLog;
use Meridian\Auth\AuthService;
use Meridian\Auth\Clock;
use Meridian\Auth\CsrfGuard;
use Meridian\Auth\LoginThrottle;
use Meridian\Auth\SessionManager;
use Meridian\Auth\SystemClock;
use Meridian\Auth\TwoFactor;
use Meridian\Category\CategoryController;
use Meridian\Category\CategoryService;
use Meridian\Config;
use Meridian\Database\Connection;
use Meridian\Job\CategoryRepository;
use Meridian\Job\JobRepository;
use Meridian\Job\JobService;
use Meridian\Job\JobValidator;
use Meridian\Job\RunCancelService;
use Meridian\Job\RunRepository;
use Meridian\Job\RunService;
use Meridian\Network\InternalTargetController;
use Meridian\Network\InternalTargetStore;
use Meridian\Runner\Http\AddressPolicy;
use Meridian\Runner\Http\DbInternalTargetSource;
use Meridian\Runner\Http\InfrastructureTargets;
use Meridian\Runner\Shell\DbShellTargetSource;
use Meridian\Runner\Shell\ShellTargetPolicy;
use Meridian\Schedule\Planner;
use Meridian\Schedule\SchedulerLease;
use Meridian\Security\AccessControl;
use Meridian\Security\GrantPolicy;
use Meridian\Security\PasswordHasher;
use Meridian\Security\SecretBox;
use Meridian\Security\SecretMasker;
use Meridian\Shell\ShellTargetController;
use Meridian\Shell\ShellTargetStore;
use Meridian\Settings\Settings;
use Meridian\Settings\SettingsController;
use Meridian\Settings\SettingsService;
use Meridian\User\AdminInvariant;
use Meridian\User\AssignmentValidator;
use Meridian\User\RoleCatalog;
use Meridian\User\UserAdminService;
use Meridian\User\UserRepository;

/**
 * Baut den Kernel mit allen Abhängigkeiten zusammen (eine Stelle für die Verdrahtung).
 */
final class AppFactory
{
    /**
     * @param SseChannel|null $sse Ausgabekanal des Live-Logs (Tests: aufzeichnend); null = {@see PhpSseChannel}
     */
    public static function create(Config $config, Connection $db, SecretBox $box, ?Clock $clock = null, ?SseChannel $sse = null): Kernel
    {
        $clock ??= new SystemClock();
        $masker = new SecretMasker();
        $hasher = new PasswordHasher();
        $users = new UserRepository($db, $hasher);
        $sessions = new SessionManager($db, $clock, $masker);
        $twoFactor = new TwoFactor($db, $box, $hasher, $clock);

        $throttle = new LoginThrottle($db, $clock);
        $audit = new AuditLog($db, $clock, $masker);
        $sessionAuth = new SessionAuth($sessions);
        $csrf = new CsrfGuard();

        $auth = new AuthService($users, $hasher, $sessions, $throttle, $audit, $clock, $twoFactor, $db);

        $access = new AccessControl();
        $settings = new Settings($db, $clock);
        $jobs = new JobRepository($db, $box, $clock);
        $categories = new CategoryRepository($db);
        // Das Web berechnet nur den ersten Termin eines Jobs (Planner::reschedule()); die Scheduler-Sperre gehört
        // dem Scheduler-Prozess und wird hier nie genommen.
        $planner = new Planner($db, $clock, new SchedulerLease($db, $clock, SchedulerLease::newOwnerId()));
        $infrastructure = InfrastructureTargets::fromConfig($config);
        $validator = new JobValidator($categories, $settings, $clock, new AddressPolicy(new DbInternalTargetSource($db), $infrastructure), $config->timezone, new ShellTargetPolicy(new DbShellTargetSource($db)));

        $kernel = new Kernel($config, $masker);
        (new AuthController($config, $auth, $sessionAuth, $csrf, $users, $clock, $twoFactor, $masker))->register($kernel);
        (new UiController($config->uiDir))->register($kernel);
        $roles = new RoleCatalog($db);
        $userAdmin = new UserAdminService(
            $db,
            $users,
            $roles,
            new GrantPolicy($access),
            new AdminInvariant($db, $users, $access),
            $audit,
            $auth,
            $hasher,
            $sessions,
            $clock,
            $throttle,
        );
        (new AccountController($config, $sessionAuth, $csrf, $users, $sessions, $auth, $audit, $masker, $clock))->register($kernel);
        (new UserController($config, $sessionAuth, $csrf, $users, $access, $roles, $sessions, $throttle, new UserPresenter($masker), $userAdmin, new AssignmentValidator($roles)))->register($kernel);
        (new AdminController($sessionAuth, $csrf, $users, $access, $audit, $throttle, $masker))->register($kernel);
        $runs = new RunRepository($db);
        (new JobController(
            $sessionAuth,
            $csrf,
            $users,
            $access,
            $jobs,
            $runs,
            $categories,
            $settings,
            $clock,
            new JobPresenter($access, $masker),
            new JobService($db, $jobs, $validator, $access, $audit, $planner),
            new RunService($db, $jobs, $access, $audit, $clock),
            new RunCancelService($db, $runs, $access, $audit, $clock),
        ))->register($kernel);
        // Live-Log per Server-Sent Events (ADR 0004 §6.1), begrenzt über live_streams.
        (new RunLiveController($sessionAuth, $users, $access, $runs, new LiveStreamSlots($db, $clock, $config->liveStreams), $clock, $sse ?? new PhpSseChannel()))->register($kernel);
        // Freigaben interner Ziele (network.internal_targets, nur Admin).
        (new InternalTargetController($sessionAuth, $csrf, $users, $access, $audit, new InternalTargetStore($db, $clock, $infrastructure), $masker))->register($kernel);
        // Ausführungsorte für Shell-Jobs (shell.targets, nur Admin) und die Auswahl im Job-Editor (jobs.edit_shell).
        (new ShellTargetController($sessionAuth, $csrf, $users, $access, $audit, new ShellTargetStore($db, $clock), $masker))->register($kernel);
        // Kategorien-Verwaltung (categories.manage, nur Admin).
        (new CategoryController($sessionAuth, $csrf, $users, $access, new CategoryService($db, $audit, $clock), $masker))->register($kernel);
        // Globale Einstellungen (settings.manage, nur Admin).
        (new SettingsController($sessionAuth, $csrf, $users, $access, new SettingsService($db, $audit, $clock), $masker))->register($kernel);

        return $kernel;
    }
}
