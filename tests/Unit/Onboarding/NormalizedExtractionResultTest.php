<?php

namespace Tests\Unit\Onboarding;

use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\Exceptions\Onboarding\InvalidExtractionResultException;
use Tests\TestCase;

class NormalizedExtractionResultTest extends TestCase
{
    public function test_accepts_a_minimal_valid_payload(): void
    {
        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'business' => [
                'name' => ['value' => 'Studio X', 'confidence_score' => 0.9, 'requires_confirmation' => false],
            ],
            'locations' => [
                [
                    'name' => ['value' => 'Central', 'confidence_score' => 0.8, 'requires_confirmation' => false],
                    'address' => ['value' => 'Str. Exemplu 1', 'confidence_score' => 0.8, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.com/'],
                ],
            ],
        ]);

        $this->assertSame('1.0', $result->schemaVersion);
        $this->assertSame('Studio X', $result->business?->name?->value);
        $this->assertCount(1, $result->locations);
        $this->assertNotNull($result->locations[0]->stableFingerprint());
    }

    public function test_rejects_missing_schema_version(): void
    {
        $this->expectException(InvalidExtractionResultException::class);

        NormalizedExtractionResult::fromArray(['business' => []]);
    }

    public function test_rejects_out_of_range_confidence_score(): void
    {
        $this->expectException(InvalidExtractionResultException::class);

        NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'business' => [
                'name' => ['value' => 'Studio X', 'confidence_score' => 1.5, 'requires_confirmation' => false],
            ],
        ]);
    }

    public function test_rejects_non_array_locations(): void
    {
        $this->expectException(InvalidExtractionResultException::class);

        NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => 'not-an-array',
        ]);
    }

    public function test_rejects_malformed_opening_hours(): void
    {
        $this->expectException(InvalidExtractionResultException::class);

        NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'business' => [
                'opening_hours' => [
                    'value' => ['mon' => 'not-a-valid-range'],
                    'confidence_score' => 0.5,
                    'requires_confirmation' => true,
                ],
            ],
        ]);
    }

    public function test_round_trips_via_to_array(): void
    {
        $raw = [
            'schema_version' => '1.0',
            'business' => [
                'name' => ['value' => 'Studio X', 'confidence_score' => 0.9, 'requires_confirmation' => false],
            ],
        ];

        $result = NormalizedExtractionResult::fromArray($raw);
        $roundTripped = NormalizedExtractionResult::fromArray($result->toArray());

        $this->assertSame('Studio X', $roundTripped->business?->name?->value);
    }
}
