<?php

namespace App\Services\Onboarding\Analyzer;

use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\DataTransferObjects\Onboarding\OnboardingAnalysisResult;
use App\Models\OnboardingDraft;
use Closure;

/**
 * Deterministic, no-I/O analyzer used in tests. Returns a schema-valid result by
 * default; can be configured to throw, or to return an intentionally invalid
 * "normalized" payload so the job's central validation-rejection path can be tested.
 */
class FakeOnboardingSourceAnalyzer implements OnboardingSourceAnalyzer
{
    private ?Closure $resultFactory = null;

    private ?AnalyzerFailedException $exceptionToThrow = null;

    public function willReturn(Closure $factory): static
    {
        $this->resultFactory = $factory;

        return $this;
    }

    public function willReturnInvalidNormalized(): static
    {
        $this->resultFactory = fn () => new OnboardingAnalysisResult(
            raw: ['note' => 'intentionally invalid for test purposes'],
            normalized: ['not_a_valid_schema' => true],
            schemaVersion: NormalizedExtractionResult::CURRENT_SCHEMA_VERSION,
        );

        return $this;
    }

    public function willThrow(AnalyzerFailedException $exception): static
    {
        $this->exceptionToThrow = $exception;

        return $this;
    }

    public function analyze(OnboardingDraft $draft): OnboardingAnalysisResult
    {
        if ($this->exceptionToThrow !== null) {
            throw $this->exceptionToThrow;
        }

        if ($this->resultFactory !== null) {
            return ($this->resultFactory)($draft);
        }

        return self::defaultResult($draft);
    }

    /**
     * A complete, self-consistent result: a business name, a location with a fully
     * confirmed schedule, and a service — all with requires_confirmation=false, so a
     * confirmation against this default can go straight through to identity_ready
     * without needing any fact decisions. Tests that need requires_confirmation=true
     * facts should use willReturn() with a custom payload instead.
     */
    public static function defaultResult(OnboardingDraft $draft): OnboardingAnalysisResult
    {
        $url = $draft->source_url ?? 'https://example.com';

        return new OnboardingAnalysisResult(
            raw: ['source_url' => $url, 'fetched_at' => now()->toISOString()],
            normalized: [
                'schema_version' => NormalizedExtractionResult::CURRENT_SCHEMA_VERSION,
                'business' => [
                    'name' => self::fact('Fake Studio'),
                    'business_type' => self::fact('salon-beauty'),
                    'website' => self::fact($url),
                    'service_at_customer_location' => self::fact(false),
                ],
                'contact' => [
                    'business_phone' => self::fact('+40700000000'),
                    'notification_email' => self::fact('owner@example.com'),
                ],
                'locations' => [
                    [
                        'name' => self::fact('Central'),
                        'address' => self::fact('Str. Exemplu 1'),
                        'city' => self::fact('Bucuresti'),
                        'phone' => self::fact('+40700000000'),
                        'opening_hours' => self::fact([
                            'mon' => '09:00 - 18:00',
                            'tue' => '09:00 - 18:00',
                            'wed' => '09:00 - 18:00',
                            'thu' => '09:00 - 18:00',
                            'fri' => '09:00 - 18:00',
                            'sat' => '',
                            'sun' => 'Inchis',
                        ]),
                        'source_urls' => [$url],
                    ],
                ],
                'services' => [
                    [
                        'name' => self::fact('Manichiura'),
                        'category' => self::fact('unghii'),
                        'price' => self::fact('100'),
                        'duration' => self::fact(30),
                        'source_urls' => [$url],
                    ],
                ],
            ],
            schemaVersion: NormalizedExtractionResult::CURRENT_SCHEMA_VERSION,
            providerMetadata: ['provider' => 'fake', 'duration_ms' => 1],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function fact(mixed $value, bool $requiresConfirmation = false, float $confidence = 0.95): array
    {
        return [
            'value' => $value,
            'confidence_score' => $confidence,
            'requires_confirmation' => $requiresConfirmation,
        ];
    }
}
