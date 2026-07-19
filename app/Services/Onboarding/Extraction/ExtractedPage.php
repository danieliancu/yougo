<?php

namespace App\Services\Onboarding\Extraction;

final readonly class ExtractedPage
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string>>  $lists
     * @param  list<list<list<string>>>  $tables  rows of cells
     * @param  list<string>  $phones
     * @param  list<string>  $emails
     * @param  list<string>  $socialLinks
     * @param  list<array<string, mixed>>  $jsonLd  decoded JSON-LD blocks (LocalBusiness/Organization/... only)
     * @param  list<string>  $breadcrumbs
     * @param  list<array{url: string, text: string}>  $links
     */
    public function __construct(
        public string $url,
        public ?string $title,
        public ?string $metaDescription,
        public array $headings,
        public string $mainText,
        public array $lists,
        public array $tables,
        public array $phones,
        public array $emails,
        public array $socialLinks,
        public array $jsonLd,
        public array $breadcrumbs,
        public array $links,
    ) {}
}
