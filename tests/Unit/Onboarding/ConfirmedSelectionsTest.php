<?php

namespace Tests\Unit\Onboarding;

use App\DataTransferObjects\Onboarding\ConfirmedSelections;
use Tests\TestCase;

class ConfirmedSelectionsTest extends TestCase
{
    public function test_excluded_staff_faq_and_policies_are_parsed_and_honored(): void
    {
        $selections = ConfirmedSelections::fromArray([
            'expected_revision' => 1,
            'excluded_staff' => ['staff-fp-1'],
            'excluded_faq' => ['faq-fp-1'],
            'excluded_policies' => ['policy-fp-1'],
        ]);

        $this->assertTrue($selections->isStaffExcluded('staff-fp-1'));
        $this->assertFalse($selections->isStaffExcluded('staff-fp-2'));
        $this->assertTrue($selections->isFaqExcluded('faq-fp-1'));
        $this->assertFalse($selections->isFaqExcluded('faq-fp-2'));
        $this->assertTrue($selections->isPolicyExcluded('policy-fp-1'));
        $this->assertFalse($selections->isPolicyExcluded('policy-fp-2'));
    }

    public function test_missing_exclusion_lists_default_to_empty(): void
    {
        $selections = ConfirmedSelections::fromArray(['expected_revision' => 1]);

        $this->assertSame([], $selections->excludedStaff);
        $this->assertSame([], $selections->excludedFaq);
        $this->assertSame([], $selections->excludedPolicies);
    }
}
