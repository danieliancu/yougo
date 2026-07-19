<?php

namespace App\Providers;

use App\Services\Onboarding\Analyzer\FakeOnboardingSourceAnalyzer;
use App\Services\Onboarding\Analyzer\NullOnboardingSourceAnalyzer;
use App\Services\Onboarding\Analyzer\OnboardingSourceAnalyzer;
use Illuminate\Support\ServiceProvider;

class OnboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OnboardingSourceAnalyzer::class, function () {
            return match (config('onboarding.analyzer.driver', 'null')) {
                'fake' => new FakeOnboardingSourceAnalyzer,
                default => new NullOnboardingSourceAnalyzer,
            };
        });
    }
}
