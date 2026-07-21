<?php

namespace Tests\Unit\Onboarding;

use App\DataTransferObjects\Onboarding\ImportedFact;
use App\Services\Onboarding\ImportedFactMerger;
use Tests\TestCase;

class ImportedFactMergerTest extends TestCase
{
    public function test_equal_values_merge_into_one(): void
    {
        $merger = new ImportedFactMerger;
        $a = $this->fact('Studio X');
        $b = $this->fact('studio x');

        $merged = $merger->merge($a, $b);

        $this->assertSame('Studio X', $merged->value);
        $this->assertFalse($merged->requiresConfirmation);
        $this->assertCount(0, $merged->conflicts);
    }

    public function test_conflicting_values_are_kept_as_conflicts_and_force_confirmation(): void
    {
        $merger = new ImportedFactMerger;
        $a = $this->fact('+40711111111');
        $b = $this->fact('+40722222222');

        $merged = $merger->merge($a, $b);

        $this->assertSame('+40711111111', $merged->value);
        $this->assertTrue($merged->requiresConfirmation);
        $this->assertCount(1, $merged->conflicts);
        $this->assertSame('+40722222222', $merged->conflicts[0]->value);
    }

    public function test_null_operands_pass_through(): void
    {
        $merger = new ImportedFactMerger;
        $a = $this->fact('Studio X');

        $this->assertSame($a, $merger->merge($a, null));
        $this->assertSame($a, $merger->merge(null, $a));
        $this->assertNull($merger->merge(null, null));
    }

    private function fact(mixed $value): ImportedFact
    {
        return ImportedFact::fromArray(['value' => $value, 'confidence_score' => 0.9, 'requires_confirmation' => false]);
    }
}
