// Thin client-side helpers over the businessTaxonomy Inertia prop shared globally by
// HandleInertiaRequests (`'businessTaxonomy' => fn () => BusinessTaxonomy::all()`).
// App\Support\BusinessTaxonomy is the single canonical source — this file has no data
// of its own, only types and lookup helpers over whatever the backend sent.

export type Industry = {
  label: string;
  label_ro?: string;
  slug: string;
  description: string;
  questions: string[];
  current_flow: string;
  future_flow?: string | null;
  safety_note?: string | null;
  capabilities_override?: { primary: string; secondary: string[] } | null;
};

export type BusinessType = {
  label: string;
  label_ro?: string;
  slug: string;
  category: string;
  default_mode: 'appointment';
  future_mode?: 'reservation' | 'lead' | null;
  description: string;
  page_focus: string;
  common_questions: string[];
  current_flow: string;
  future_flow?: string | null;
  safety_copy?: string | null;
  industries: Industry[];
  primary_capability: string;
  secondary_capabilities: string[];
  collected_fields: string[];
  required_fields: string[];
  conditional_fields: Record<string, string>;
  urgency_rules: Record<string, string>;
  safety_instructions?: string | null;
  dashboard_labels: Record<string, string>;
  recommended_modules: string[];
  capability_availability: { appointment: boolean; request: boolean; reservation: boolean };
  aliases: string[];
};

export function localizedLabel(item: { label: string; label_ro?: string }, locale?: string | null): string {
  return locale === 'ro' && item.label_ro ? item.label_ro : item.label;
}

export function findBusinessType(taxonomy: BusinessType[], slug?: string | null): BusinessType | undefined {
  return taxonomy.find((item) => item.slug === slug);
}

export function findIndustry(taxonomy: BusinessType[], businessTypeSlug?: string | null, industrySlug?: string | null): Industry | undefined {
  return findBusinessType(taxonomy, businessTypeSlug)?.industries.find((item) => item.slug === industrySlug);
}

export function normalizeBusinessTypeSlug(taxonomy: BusinessType[], value?: string | null): string {
  if (!value) return '';
  const normalized = value.trim().toLowerCase();
  return taxonomy.find((item) => item.slug === value || item.label.toLowerCase() === normalized)?.slug ?? '';
}

export function normalizeIndustrySlug(taxonomy: BusinessType[], value?: string | null, businessTypeSlug?: string | null): string {
  if (!value) return '';
  const normalized = value.trim().toLowerCase();
  const groups = businessTypeSlug ? [findBusinessType(taxonomy, businessTypeSlug)].filter(Boolean) as BusinessType[] : taxonomy;
  for (const group of groups) {
    const industry = group.industries.find((item) => item.slug === value || item.label.toLowerCase() === normalized);
    if (industry) return industry.slug;
  }
  return '';
}
