<?php

namespace Tests\Unit\Onboarding;

use App\Exceptions\Onboarding\InvalidExtractionResultException;
use App\Services\Onboarding\OnboardingHoursValidator;
use Tests\TestCase;

class OnboardingHoursValidatorTest extends TestCase
{
    public function test_accepts_full_hh_mm_ranges(): void
    {
        $validator = new OnboardingHoursValidator;

        $result = $validator->validate(['mon' => '09:00 - 18:00']);

        $this->assertSame('09:00 - 18:00', $result['mon']);
    }

    public function test_accepts_an_open_hour_with_no_minutes_shorthand(): void
    {
        // Real extraction from a live site: "Luni - Vineri: 10-21:00" — the open time
        // has no minutes because it's on the hour, but the close time does. The AI
        // copies that shorthand verbatim rather than normalizing it before extraction.
        $validator = new OnboardingHoursValidator;

        $result = $validator->validate(['mon' => '10-21:00']);

        $this->assertSame('10:00 - 21:00', $result['mon']);
    }

    public function test_accepts_a_close_hour_with_no_minutes_shorthand(): void
    {
        $validator = new OnboardingHoursValidator;

        $result = $validator->validate(['mon' => '10:00-21']);

        $this->assertSame('10:00 - 21:00', $result['mon']);
    }

    public function test_accepts_both_sides_with_no_minutes_shorthand(): void
    {
        $validator = new OnboardingHoursValidator;

        $result = $validator->validate(['mon' => '10-21']);

        $this->assertSame('10:00 - 21:00', $result['mon']);
    }

    public function test_accepts_closed_marker_in_romanian_and_english(): void
    {
        $validator = new OnboardingHoursValidator;

        $result = $validator->validate(['sun' => 'Inchis', 'sat' => 'closed']);

        $this->assertSame('Inchis', $result['sun']);
        $this->assertSame('Inchis', $result['sat']);
    }

    public function test_rejects_a_value_that_is_not_a_time_range(): void
    {
        $validator = new OnboardingHoursValidator;

        $this->expectException(InvalidExtractionResultException::class);

        $validator->validate(['mon' => 'Sunam pentru programare']);
    }

    public function test_rejects_a_range_where_open_is_after_close(): void
    {
        $validator = new OnboardingHoursValidator;

        $this->expectException(InvalidExtractionResultException::class);

        $validator->validate(['mon' => '21:00 - 09:00']);
    }

    public function test_rejects_an_unknown_day_key(): void
    {
        $validator = new OnboardingHoursValidator;

        $this->expectException(InvalidExtractionResultException::class);

        $validator->validate(['monday' => '09:00 - 18:00']);
    }
}
