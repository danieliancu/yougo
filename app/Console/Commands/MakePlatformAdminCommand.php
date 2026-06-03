<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;

class MakePlatformAdminCommand extends Command
{
    protected $signature = 'yougo:make-platform-admin {username} {--password=admin}';

    protected $description = 'Create or update a dedicated YouGo platform admin account.';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $password = (string) $this->option('password');

        if ($username === '' || $password === '') {
            $this->error('Username and password are required.');

            return self::FAILURE;
        }

        $admin = PlatformAdmin::query()->updateOrCreate(
            ['username' => $username],
            [
                'name' => 'Platform Admin',
                'password' => $password,
            ],
        );

        $this->info("Platform Admin account {$admin->username} is ready.");

        return self::SUCCESS;
    }
}
