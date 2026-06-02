<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakePlatformAdminCommand extends Command
{
    protected $signature = 'yougo:make-platform-admin {email}';

    protected $description = 'Promote an existing user to YouGo platform admin.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user found for {$email}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_admin' => true])->save();

        $this->info("{$user->email} can now access Platform Admin.");

        return self::SUCCESS;
    }
}
