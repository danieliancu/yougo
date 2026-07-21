<?php

namespace App\Providers;

use App\Services\Onboarding\Analyzer\FakeOnboardingSourceAnalyzer;
use App\Services\Onboarding\Analyzer\GeminiWebsiteOnboardingAnalyzer;
use App\Services\Onboarding\Analyzer\NullOnboardingSourceAnalyzer;
use App\Services\Onboarding\Analyzer\OnboardingSourceAnalyzer;
use App\Services\Onboarding\DnsOnboardingHostResolver;
use App\Services\Onboarding\Fetcher\GuzzleOnboardingHttpTransport;
use App\Services\Onboarding\Fetcher\HttpWebsiteSourceFetcher;
use App\Services\Onboarding\Fetcher\OnboardingHttpTransport;
use App\Services\Onboarding\Fetcher\OnboardingSourceFetcher;
use App\Services\Onboarding\OnboardingHostResolver;
use Illuminate\Support\ServiceProvider;

class OnboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OnboardingHostResolver::class, DnsOnboardingHostResolver::class);
        $this->app->bind(OnboardingHttpTransport::class, GuzzleOnboardingHttpTransport::class);
        $this->app->bind(OnboardingSourceFetcher::class, HttpWebsiteSourceFetcher::class);

        $this->app->bind(OnboardingSourceAnalyzer::class, function ($app) {
            return match (config('onboarding.analyzer.driver', 'null')) {
                'fake' => new FakeOnboardingSourceAnalyzer,
                'gemini_website' => $app->make(GeminiWebsiteOnboardingAnalyzer::class),
                default => new NullOnboardingSourceAnalyzer,
            };
        });
    }
}
