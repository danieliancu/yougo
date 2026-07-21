<?php

namespace App\DataTransferObjects\Onboarding;

use InvalidArgumentException;

/**
 * The user's decisions for a draft confirmation. An entirely empty payload is valid
 * only when nothing in the normalized result required a decision (see
 * OnboardingDraftConfirmationService); this DTO only validates shape, not completeness.
 */
final readonly class ConfirmedSelections
{
    public const DECISION_CONFIRMED = 'confirmed';

    public const DECISION_CORRECTED = 'corrected';

    public const DECISION_EXCLUDED = 'excluded';

    private const VALID_DECISIONS = [self::DECISION_CONFIRMED, self::DECISION_CORRECTED, self::DECISION_EXCLUDED];

    /**
     * @param  array<string, array{decision: string, value?: mixed}>  $facts  keyed by stable fact path, e.g. "business.name", "locations.<fingerprint>.opening_hours"
     * @param  list<string>  $excludedLocations
     * @param  list<string>  $excludedServices
     * @param  list<string>  $excludedStaff
     * @param  list<string>  $excludedFaq
     * @param  list<string>  $excludedPolicies
     * @param  array<string, bool>  $overwrite
     */
    public function __construct(
        public int $expectedRevision,
        public array $facts = [],
        public array $excludedLocations = [],
        public array $excludedServices = [],
        public array $excludedStaff = [],
        public array $excludedFaq = [],
        public array $excludedPolicies = [],
        public array $overwrite = [],
    ) {}

    public static function fromArray(array $raw): self
    {
        if (! isset($raw['expected_revision']) || ! is_numeric($raw['expected_revision'])) {
            throw new InvalidArgumentException('confirmedSelections is missing a numeric expected_revision.');
        }

        $facts = [];

        foreach (($raw['facts'] ?? []) as $path => $decision) {
            if (! is_string($path) || ! is_array($decision) || ! isset($decision['decision'])) {
                throw new InvalidArgumentException('confirmedSelections.facts entries must be {decision, value?} objects.');
            }

            if (! in_array($decision['decision'], self::VALID_DECISIONS, true)) {
                throw new InvalidArgumentException("confirmedSelections.facts.{$path} has an invalid decision.");
            }

            if ($decision['decision'] === self::DECISION_CORRECTED && ! array_key_exists('value', $decision)) {
                throw new InvalidArgumentException("confirmedSelections.facts.{$path} decision \"corrected\" requires a value.");
            }

            $facts[$path] = $decision;
        }

        return new self(
            expectedRevision: (int) $raw['expected_revision'],
            facts: $facts,
            excludedLocations: array_values(array_map('strval', $raw['excluded_locations'] ?? [])),
            excludedServices: array_values(array_map('strval', $raw['excluded_services'] ?? [])),
            excludedStaff: array_values(array_map('strval', $raw['excluded_staff'] ?? [])),
            excludedFaq: array_values(array_map('strval', $raw['excluded_faq'] ?? [])),
            excludedPolicies: array_values(array_map('strval', $raw['excluded_policies'] ?? [])),
            overwrite: array_map('boolval', $raw['overwrite'] ?? []),
        );
    }

    /**
     * @return array{decision: string, value?: mixed}|null
     */
    public function decisionFor(string $factPath): ?array
    {
        return $this->facts[$factPath] ?? null;
    }

    public function isLocationExcluded(string $key): bool
    {
        return in_array($key, $this->excludedLocations, true);
    }

    public function isServiceExcluded(string $key): bool
    {
        return in_array($key, $this->excludedServices, true);
    }

    public function isStaffExcluded(string $key): bool
    {
        return in_array($key, $this->excludedStaff, true);
    }

    public function isFaqExcluded(string $key): bool
    {
        return in_array($key, $this->excludedFaq, true);
    }

    public function isPolicyExcluded(string $key): bool
    {
        return in_array($key, $this->excludedPolicies, true);
    }

    public function shouldOverwrite(string $field): bool
    {
        return $this->overwrite[$field] ?? false;
    }
}
