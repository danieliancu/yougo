<?php

namespace App\Console\Commands;

use App\Mail\TestEmailMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestEmailCommand extends Command
{
    protected $signature = 'yougo:test-email {email}';

    protected $description = 'Send a test email using the configured Laravel mailer.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        try {
            Mail::to($email)->send(new TestEmailMail);

            $this->info("Test email sent to {$email}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error("Test email could not be sent: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }
}
