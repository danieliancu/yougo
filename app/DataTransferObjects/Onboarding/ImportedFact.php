<?php

namespace App\DataTransferObjects\Onboarding;

use App\Exceptions\Onboarding\InvalidExtractionResultException;

/**
 * A single piece of imported information, carrying its provenance and confidence
 * alongside the value itself. This is the atomic unit the review UI (Task 2) will
 * eventually let a user confirm/correct/exclude.
 */
final readonly class ImportedFact
{
    /**
     * @param  list<self>  $alternatives
     * @param  list<self>  $conflicts
     */
    public function __construct(
        public mixed $value,
        public ?string $sourceUrl,
        public float $confidenceScore,
        public bool $requiresConfirmation,
        public ?string $reason = null,
        public array $alternatives = [],
        public array $conflicts = [],
    ) {}

    public static function fromArray(array $raw): self
    {
        if (! array_key_exists('value', $raw)) {
            throw new InvalidExtractionResultException('ImportedFact is missing a "value" key.');
        }

        $confidence = $raw['confidence_score'] ?? 0.0;

        if (! is_numeric($confidence)) {
            throw new InvalidExtractionResultException('ImportedFact confidence_score must be numeric.');
        }

        $confidence = (float) $confidence;

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidExtractionResultException('ImportedFact confidence_score must be between 0 and 1.');
        }

        return new self(
            value: $raw['value'],
            sourceUrl: isset($raw['source_url']) ? (string) $raw['source_url'] : null,
            confidenceScore: $confidence,
            requiresConfirmation: (bool) ($raw['requires_confirmation'] ?? false),
            reason: isset($raw['reason']) ? (string) $raw['reason'] : null,
            alternatives: self::parseFactList($raw['alternatives'] ?? []),
            conflicts: self::parseFactList($raw['conflicts'] ?? []),
        );
    }

    /**
     * @return list<self>
     */
    private static function parseFactList(mixed $list): array
    {
        if (! is_array($list)) {
            throw new InvalidExtractionResultException('ImportedFact alternatives/conflicts must be an array.');
        }

        return array_values(array_map(
            function ($item) {
                if (! is_array($item)) {
                    throw new InvalidExtractionResultException('ImportedFact alternatives/conflicts entries must be arrays.');
                }

                return self::fromArray($item);
            },
            $list
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'source_url' => $this->sourceUrl,
            'confidence_score' => $this->confidenceScore,
            'requires_confirmation' => $this->requiresConfirmation,
            'reason' => $this->reason,
            'alternatives' => array_map(static fn (self $fact) => $fact->toArray(), $this->alternatives),
            'conflicts' => array_map(static fn (self $fact) => $fact->toArray(), $this->conflicts),
        ];
    }

    public function withValue(mixed $value): self
    {
        return new self($value, $this->sourceUrl, $this->confidenceScore, $this->requiresConfirmation, $this->reason, $this->alternatives, $this->conflicts);
    }

    public function withRequiresConfirmation(bool $requiresConfirmation): self
    {
        return new self($this->value, $this->sourceUrl, $this->confidenceScore, $requiresConfirmation, $this->reason, $this->alternatives, $this->conflicts);
    }

    /**
     * @param  list<self>  $conflicts
     */
    public function withConflicts(array $conflicts, bool $forceRequiresConfirmation = true): self
    {
        return new self(
            $this->value,
            $this->sourceUrl,
            $this->confidenceScore,
            $forceRequiresConfirmation ? true : $this->requiresConfirmation,
            $this->reason,
            $this->alternatives,
            $conflicts,
        );
    }
}
