<?php

namespace App\DataTransferObjects\Onboarding;

use App\Services\Onboarding\OnboardingHoursValidator;
use Illuminate\Support\Str;

final readonly class LocationData
{
    /**
     * @param  list<string>  $sourceUrls  normalized, deduplicated pages this location was found on — provenance only, never part of identity
     */
    public function __construct(
        public ?ImportedFact $name = null,
        public ?ImportedFact $address = null,
        public ?ImportedFact $city = null,
        public ?ImportedFact $county = null,
        public ?ImportedFact $postcode = null,
        public ?ImportedFact $country = null,
        public ?ImportedFact $phone = null,
        public ?ImportedFact $openingHours = null,
        public array $sourceUrls = [],
        public ?string $externalId = null,
        public ?string $fingerprint = null,
        public bool $isTemporaryFingerprint = false,
    ) {}

    public static function fromArray(array $raw): self
    {
        $validator = new OnboardingHoursValidator;

        $openingHours = isset($raw['opening_hours']) ? ImportedFact::fromArray($raw['opening_hours']) : null;

        if ($openingHours !== null && is_array($openingHours->value)) {
            $openingHours = $openingHours->withValue($validator->validate($openingHours->value));
        }

        return new self(
            name: isset($raw['name']) ? ImportedFact::fromArray($raw['name']) : null,
            address: isset($raw['address']) ? ImportedFact::fromArray($raw['address']) : null,
            city: isset($raw['city']) ? ImportedFact::fromArray($raw['city']) : null,
            county: isset($raw['county']) ? ImportedFact::fromArray($raw['county']) : null,
            postcode: isset($raw['postcode']) ? ImportedFact::fromArray($raw['postcode']) : null,
            country: isset($raw['country']) ? ImportedFact::fromArray($raw['country']) : null,
            phone: isset($raw['phone']) ? ImportedFact::fromArray($raw['phone']) : null,
            openingHours: $openingHours,
            sourceUrls: array_values(array_unique(array_map('strval', $raw['source_urls'] ?? []))),
            externalId: isset($raw['external_id']) ? (string) $raw['external_id'] : null,
            fingerprint: isset($raw['fingerprint']) ? (string) $raw['fingerprint'] : null,
            isTemporaryFingerprint: (bool) ($raw['is_temporary_fingerprint'] ?? false),
        );
    }

    /**
     * @return array<string, ?ImportedFact>
     */
    public function factMap(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'county' => $this->county,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phone' => $this->phone,
            'opening_hours' => $this->openingHours,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function fieldKinds(): array
    {
        return [
            'name' => 'text',
            'address' => 'text',
            'city' => 'text',
            'county' => 'text',
            'postcode' => 'text',
            'country' => 'text',
            'phone' => 'text',
            'opening_hours' => 'hours',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name?->toArray(),
            'address' => $this->address?->toArray(),
            'city' => $this->city?->toArray(),
            'county' => $this->county?->toArray(),
            'postcode' => $this->postcode?->toArray(),
            'country' => $this->country?->toArray(),
            'phone' => $this->phone?->toArray(),
            'opening_hours' => $this->openingHours?->toArray(),
            'source_urls' => $this->sourceUrls,
            'external_id' => $this->externalId,
            'fingerprint' => $this->fingerprint,
            'is_temporary_fingerprint' => $this->isTemporaryFingerprint,
        ];
    }

    public function with(
        ?ImportedFact $name = null,
        ?ImportedFact $address = null,
        ?ImportedFact $city = null,
        ?ImportedFact $county = null,
        ?ImportedFact $postcode = null,
        ?ImportedFact $country = null,
        ?ImportedFact $phone = null,
        ?ImportedFact $openingHours = null,
        ?array $sourceUrls = null,
        ?string $externalId = null,
        ?string $fingerprint = null,
        ?bool $isTemporaryFingerprint = null,
    ): self {
        return new self(
            name: $name ?? $this->name,
            address: $address ?? $this->address,
            city: $city ?? $this->city,
            county: $county ?? $this->county,
            postcode: $postcode ?? $this->postcode,
            country: $country ?? $this->country,
            phone: $phone ?? $this->phone,
            openingHours: $openingHours ?? $this->openingHours,
            sourceUrls: $sourceUrls ?? $this->sourceUrls,
            externalId: $externalId ?? $this->externalId,
            fingerprint: $fingerprint ?? $this->fingerprint,
            isTemporaryFingerprint: $isTemporaryFingerprint ?? $this->isTemporaryFingerprint,
        );
    }

    /**
     * Pure, data-only identity fingerprint — never includes the exact page URL, so the
     * same location found on multiple pages resolves to the same identity. Returns null
     * when there isn't enough data for a stable identity (name plus at least one of
     * address/phone); OnboardingEntityDeduplicator assigns a temporary, draft-scoped
     * fingerprint in that case — this method has no knowledge of drafts.
     */
    public function stableFingerprint(): ?string
    {
        $name = self::normalizeName($this->name?->value);
        $address = self::normalizeAddress($this->address?->value);
        $phone = self::normalizePhone($this->phone?->value);

        if ($name === '' || ($address === '' && $phone === '')) {
            return null;
        }

        // city/county/postcode are deliberately NOT part of the identity hash: a small
        // local business's street address already pins down "where", and these three are
        // the fields AI calls disagree on most (one batch notes "Sector 1", another
        // leaves it blank) — including them here would let that inconsistency alone
        // split one real location into several. They're still carried and merged as
        // ordinary fields, just not used to decide identity.
        return hash('sha256', implode('|', [$name, $address, $phone]));
    }

    /**
     * The name half of the identity, exposed for OnboardingEntityDeduplicator's fuzzy
     * consolidation pass — merging a nameless-address entry into an existing location
     * when their normalized names match, which stableFingerprint() alone can't do since
     * it needs address/phone too.
     */
    public function normalizedNameKey(): string
    {
        return self::normalizeName($this->name?->value);
    }

    /**
     * The address half of the identity, exposed for OnboardingEntityDeduplicator's
     * prefix-based consolidation pass — see that method for why.
     */
    public function normalizedAddressKey(): string
    {
        return self::normalizeAddress($this->address?->value);
    }

    /**
     * The city, exposed for OnboardingEntityDeduplicator's temporary-entry consolidation
     * pass as a last-resort grouping key when an entry has neither a usable name nor an
     * address at all (e.g. a page fragment stating only "Bucuresti" and a schedule).
     */
    public function normalizedCityKey(): string
    {
        return self::normalize($this->city?->value);
    }

    /**
     * The phone half of the identity, exposed for OnboardingEntityDeduplicator's
     * complementary-contact-details consolidation pass — see that method for why.
     */
    public function normalizedPhoneKey(): string
    {
        return self::normalizePhone($this->phone?->value);
    }

    /**
     * The street-name half of a canonical street+number address signature, exposed for
     * OnboardingEntityDeduplicator's cross-name address consolidation pass — see that
     * method for why identity sometimes needs to be decided by address alone, ignoring
     * name entirely.
     */
    public function normalizedStreetNameKey(): string
    {
        return self::streetNameAndHouseNumber($this->address?->value)[0];
    }

    /**
     * The house-number half of the same signature — see normalizedStreetNameKey().
     */
    public function normalizedHouseNumberKey(): string
    {
        return self::streetNameAndHouseNumber($this->address?->value)[1];
    }

    /**
     * Splits an address into (street name, house number), tolerating the variance a real
     * import produced across mentions of one identical address: the number appearing
     * before or after the street name ("20 Strada X" vs "Strada X 20"), an inserted "nr"
     * marker shifting the number's position ("Strada X nr.20" / "Strada X, nr. 20, sector
     * 1"), and a trailing city/postcode/country segment a plain string-prefix comparison
     * can't see past. Extracting the number and the remaining street text separately, and
     * comparing those two independently of order, catches all of these.
     *
     * Works from the comma-preserving abbreviation normalization, not normalizeAddress()
     * — the house number is found and removed FIRST (commas don't interfere with that;
     * a comma is no more a word character than a space), and only THEN is everything
     * from the first remaining comma onward dropped from the street name specifically.
     * Doing it in the other order (as an earlier version did, via normalizeAddress()'s
     * now-removed comma truncation) breaks the moment a mention uses a comma as its own
     * internal separator ("Strada X, nr. 20, sector 1") — the number itself sits right
     * after that first comma and would be discarded along with the real tail.
     *
     * @return array{0: string, 1: string}
     */
    private static function streetNameAndHouseNumber(mixed $value): array
    {
        $address = self::applyAddressAbbreviations($value);

        if ($address === '') {
            return ['', ''];
        }

        // The "nr" marker (already normalized from "Nr."/"Numarul") is the clearest
        // signal of "the house number comes next" when present.
        if (preg_match('/\bnr\s+(\d+[a-z]?)\b/u', $address, $matches)) {
            $number = $matches[1];
            $street = preg_replace('/\bnr\s+'.preg_quote($number, '/').'\b/u', '', $address, 1) ?? $address;

            return [self::streetNameBeforeTrailingSegment($street), $number];
        }

        // Without a marker, fall back to the first standalone number token — but capped
        // at 3 digits so a Romanian postal code (5-6 digits, e.g. "011098") elsewhere in
        // the address can never be mistaken for a house number; a real house number
        // essentially never runs longer than 3 digits + a unit letter.
        if (preg_match('/\b(\d{1,3}[a-z]?)\b/u', $address, $matches)) {
            $number = $matches[1];
            $street = preg_replace('/\b'.preg_quote($number, '/').'\b/u', '', $address, 1) ?? $address;

            return [self::streetNameBeforeTrailingSegment($street), $number];
        }

        return [self::streetNameBeforeTrailingSegment($address), ''];
    }

    /**
     * Drops a trailing city/postcode/country segment ("..., Bucuresti 011098, Romania")
     * from an address the house number has already been extracted from, then strips
     * remaining punctuation the same way normalizeAddress() does.
     */
    private static function streetNameBeforeTrailingSegment(string $address): string
    {
        $commaPosition = strpos($address, ',');

        if ($commaPosition !== false) {
            $address = substr($address, 0, $commaPosition);
        }

        $address = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $address) ?? $address;

        return Str::of($address)->squish()->toString();
    }

    /**
     * Independent AI calls over different pages of the same site describe the same
     * premises inconsistently — "Salonul Titulescu" vs "Salon Titulescu" — because each
     * call has no memory of how a prior call phrased it. Stripping the generic Romanian
     * premises word (which carries no identity, only the proper noun after it does)
     * collapses that variance before it ever reaches the fingerprint hash.
     */
    private const NAME_GENERIC_PREFIXES = [
        'salonul', 'salon', 'cabinetul', 'cabinet', 'magazinul', 'magazin',
        'clinica', 'atelierul', 'atelier', 'studioul', 'studio',
    ];

    private static function normalizeName(mixed $value): string
    {
        $normalized = self::normalize($value);

        if ($normalized === '') {
            return '';
        }

        foreach (self::NAME_GENERIC_PREFIXES as $prefix) {
            if ($normalized === $prefix) {
                continue; // the whole name is just the generic word — nothing to strip it down to.
            }

            if (str_starts_with($normalized, "{$prefix} ")) {
                return substr($normalized, strlen($prefix) + 1);
            }
        }

        return $normalized;
    }

    /**
     * Standardizes the Romanian street/number abbreviations ("Strada" vs "Str.", "Bulevardul"
     * vs "B-dul"/"Bd.", "Numărul" vs "Nr.") and strips punctuation before hashing, so
     * formatting-only differences
     * between two AI calls describing the same address don't produce different
     * fingerprints. Deliberately does not touch trailing unit/entrance letters (e.g. "1 B")
     * — those can be a genuinely different address, not just a formatting choice.
     *
     * Deliberately does NOT drop the city/postcode/country tail some mentions include and
     * others don't ("Str. X 20" vs "Str. X 20, Bucuresti, Romania") — an earlier version
     * of this method truncated at the first comma to get rid of it, but a real address
     * turned out to use commas as its OWN internal separator ("Str. X, nr. 20, sector 1"),
     * and truncating there threw the house number away with the rest. Consumers that need
     * the tail gone (streetNameAndHouseNumber()) filter it out by other means instead.
     */
    private static function normalizeAddress(mixed $value): string
    {
        $normalized = self::applyAddressAbbreviations($value);

        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;

        return Str::of($normalized)->squish()->toString();
    }

    /**
     * The str/nr abbreviation standardization shared by normalizeAddress() (which then
     * strips all remaining punctuation, commas included) and streetNameAndHouseNumber()
     * (which needs commas left in a little longer — see there for why).
     */
    private static function applyAddressAbbreviations(mixed $value): string
    {
        $normalized = self::normalize($value);

        if ($normalized === '') {
            return '';
        }

        // Trailing space after the replacement matters: source text routinely omits the
        // space after the abbreviation's period ("nr.20", "Str.Miron") — without it here,
        // the replacement fuses straight onto the following word/number ("nr20"), which
        // then breaks streetNameAndHouseNumber()'s "nr <number>" match entirely. squish()
        // at each call site collapses the occasional resulting double space back down.
        $normalized = preg_replace('/\bstrada\b|\bstr\.?\b/u', 'str ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bbulevardul\b|\bbulevard\b|\bb-dul\b|\bbd\.?\b/u', 'bd ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bnumarul\b|\bnr\.?\b/u', 'nr ', $normalized) ?? $normalized;

        return $normalized;
    }

    private static function normalize(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Str::of($value)->ascii()->lower()->squish()->toString();
    }

    private static function normalizePhone(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return preg_replace('/[^0-9+]/', '', $value) ?? '';
    }
}
