export type ImportedFact = {
  value: unknown;
  source_url: string | null;
  confidence_score: number;
  requires_confirmation: boolean;
  reason: string | null;
  alternatives: ImportedFact[];
  conflicts: ImportedFact[];
} | null;

export type EntityRecord = Record<string, unknown> & {
  fingerprint: string | null;
  is_temporary_fingerprint: boolean;
  source_urls: string[];
  external_id: string | null;
};

export type OnboardingFieldSchema = {
  business: Record<string, string>;
  contact: Record<string, string>;
  locations: Record<string, string>;
  services: Record<string, string>;
  staff: Record<string, string>;
  faq: Record<string, string>;
  policies: Record<string, string>;
};

export type NormalizedExtractionResult = {
  schema_version: string;
  business: Record<string, ImportedFact> | null;
  contact: Record<string, ImportedFact> | null;
  locations: EntityRecord[];
  services: EntityRecord[];
  staff: EntityRecord[];
  faq: EntityRecord[];
  policies: EntityRecord[];
};

export type DraftStatus = 'pending' | 'analysing' | 'review_required' | 'analysis_failed' | 'confirmed' | 'superseded';

export type AnalysisProgress = {
  pages_discovered: number | null;
  pages_processed: number | null;
  warnings: string[];
  stop_reason: string | null;
} | null;

export type Draft = {
  id: number;
  status: DraftStatus;
  source_type: 'url' | 'manual';
  source_url: string | null;
  revision: number;
  attempt_count: number;
  normalized_extraction_result: NormalizedExtractionResult | null;
  validation_errors: unknown;
  failure_code: string | null;
  failure_message: string | null;
  started_at: string | null;
  finished_at: string | null;
  analysis_progress: AnalysisProgress;
};

export type FactDecision = { decision: 'confirmed'; value?: undefined } | { decision: 'corrected'; value: unknown };

export type ExclusionState = {
  locations: Set<string>;
  services: Set<string>;
  staff: Set<string>;
  faq: Set<string>;
  policies: Set<string>;
};

export function csrfHeaders(): Record<string, string> {
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  const xsrf = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')[1];

  return {
    'X-Requested-With': 'XMLHttpRequest',
    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : token ? { 'X-CSRF-TOKEN': token } : {}),
  };
}
