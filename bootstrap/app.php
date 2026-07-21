<?php

use App\Console\Commands\MakePlatformAdminCommand;
use App\Console\Commands\PurgeOnboardingRawResultsCommand;
use App\Console\Commands\StripeCheckCommand;
use App\Console\Commands\TestEmailCommand;
use App\Console\Commands\WhatsappActivateCommand;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        MakePlatformAdminCommand::class,
        StripeCheckCommand::class,
        TestEmailCommand::class,
        WhatsappActivateCommand::class,
        PurgeOnboardingRawResultsCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(PurgeOnboardingRawResultsCommand::class)->daily();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'platform_admin' => EnsurePlatformAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
