<?php

namespace App\Console\Commands;

use App\Support\StripePlans;
use Illuminate\Console\Command;

class StripeCheckCommand extends Command
{
    protected $signature = 'yougo:stripe-check';

    protected $description = 'Validate YouGo Stripe billing configuration.';

    public function handle(): int
    {
        $errors = StripePlans::configuredPriceErrors();

        if ($errors === []) {
            $this->info('Stripe billing configuration looks valid.');

            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        return self::FAILURE;
    }
}
