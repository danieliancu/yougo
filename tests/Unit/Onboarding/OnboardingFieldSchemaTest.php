<?php

namespace Tests\Unit\Onboarding;

use App\DataTransferObjects\Onboarding\BusinessProfileData;
use App\DataTransferObjects\Onboarding\ContactData;
use App\DataTransferObjects\Onboarding\FaqEntryData;
use App\DataTransferObjects\Onboarding\LocationData;
use App\DataTransferObjects\Onboarding\PolicyEntryData;
use App\DataTransferObjects\Onboarding\ServiceData;
use App\DataTransferObjects\Onboarding\StaffData;
use App\Services\Onboarding\OnboardingFieldSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the contract the review UI depends on: every key a DTO's factMap() can expose
 * (and therefore every fact OnboardingDraftConfirmationService::collectMissingFactDecisions()
 * can require a decision for) must have a matching fieldKinds() entry, and vice versa. A
 * mismatch here means the review screen can silently omit a required field — exactly the
 * bug this schema was introduced to make structurally impossible.
 */
class OnboardingFieldSchemaTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function dtoProvider(): array
    {
        return [
            'business' => [BusinessProfileData::class, 'business'],
            'contact' => [ContactData::class, 'contact'],
            'locations' => [LocationData::class, 'locations'],
            'services' => [ServiceData::class, 'services'],
            'staff' => [StaffData::class, 'staff'],
            'faq' => [FaqEntryData::class, 'faq'],
            'policies' => [PolicyEntryData::class, 'policies'],
        ];
    }

    #[DataProvider('dtoProvider')]
    public function test_field_kinds_keys_match_fact_map_keys_exactly(string $dtoClass, string $schemaKey): void
    {
        $factMapKeys = array_keys((new $dtoClass)->factMap());
        $fieldKindsKeys = array_keys($dtoClass::fieldKinds());

        sort($factMapKeys);
        sort($fieldKindsKeys);

        $this->assertSame(
            $factMapKeys,
            $fieldKindsKeys,
            "{$dtoClass}::fieldKinds() must expose exactly the same keys as factMap() — a mismatch means the review UI (driven by OnboardingFieldSchema) either can't show a field the backend can require confirmation for, or offers one the backend doesn't recognize."
        );

        $this->assertArrayHasKey($schemaKey, OnboardingFieldSchema::forReview());
        $this->assertSame($dtoClass::fieldKinds(), OnboardingFieldSchema::forReview()[$schemaKey]);
    }

    #[DataProvider('dtoProvider')]
    public function test_field_kinds_values_are_known_ui_kinds(string $dtoClass): void
    {
        $validKinds = ['text', 'multiline', 'boolean', 'hours', 'list', 'social_links', 'location_checklist', 'number', 'price'];

        foreach ($dtoClass::fieldKinds() as $field => $kind) {
            $this->assertContains($kind, $validKinds, "{$dtoClass}::fieldKinds()[{$field}] has an unrecognized UI kind [{$kind}].");
        }
    }
}
