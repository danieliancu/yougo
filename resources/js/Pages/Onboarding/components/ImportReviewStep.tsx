import { ReactNode, useState } from 'react';
import { ChevronDown, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Card, Field, Input, SecondaryButton } from '@/Components/Ui';
import { useT } from '@/i18n';
import { EntityRecord, ExclusionState, FactDecision, ImportedFact, NormalizedExtractionResult, OnboardingFieldSchema } from '../types';

const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;
const DAY_LABEL_KEYS: Record<string, string> = {
  mon: 'monday', tue: 'tuesday', wed: 'wednesday', thu: 'thursday', fri: 'friday', sat: 'saturday', sun: 'sunday',
};

type FieldKind = 'text' | 'multiline' | 'boolean' | 'hours' | 'list' | 'social_links' | 'location_checklist' | 'number' | 'price';
type FieldConfig = { key: string; labelKey: string; kind: FieldKind };

type SectionReviewStatus = 'needs_review' | 'confirmed' | 'none';

/**
 * One rule, shared by every section card (identity, locations, services, staff, faq,
 * policies): "needs review" if any fact in it still requires confirmation and hasn't
 * been locally confirmed yet (mirrors FactRow's own needsReview check exactly, so the
 * section-level pill never disagrees with the individual field badges inside it),
 * "confirmed" if the section has data but nothing outstanding, "none" if it has no data
 * at all — a business/contact field never extracted, or an entity list that's empty.
 */
function computeSectionReviewStatus(
  facts: Array<{ fact: ImportedFact | null | undefined; path: string }>,
  factDecisions: Record<string, FactDecision>
): SectionReviewStatus {
  const present = facts.filter(({ fact }) => fact !== null && fact !== undefined);

  if (present.length === 0) return 'none';

  const needsReview = present.some(
    ({ fact, path }) => Boolean(fact?.requires_confirmation) && factDecisions[path]?.decision !== 'confirmed'
  );

  return needsReview ? 'needs_review' : 'confirmed';
}

function SectionStatusPill({ status }: { status: SectionReviewStatus }) {
  const t = useT();

  if (status === 'none') return null;

  return status === 'needs_review'
    ? <Badge tone="amber">{t('importNeedsReview')}</Badge>
    : <Badge tone="green">{t('importSectionConfirmed')}</Badge>;
}

type PriceValue = { type: 'fixed' | 'from'; amount?: number } | { type: 'range'; min?: number; max?: number };

function isPriceValue(value: unknown): value is PriceValue {
  return typeof value === 'object' && value !== null && 'type' in value
    && ['fixed', 'from', 'range'].includes((value as { type: unknown }).type as string);
}

function trimNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : String(Math.round(value * 100) / 100);
}

/** Mirrors OnboardingPriceFormatter::toDisplayString() (app/Services/Onboarding/OnboardingPriceFormatter.php) so the review screen shows the same text the confirmed price will resolve to. */
function formatPriceValue(value: unknown, t: (key: string) => string): string {
  if (!isPriceValue(value)) return '';

  if (value.type === 'fixed') return typeof value.amount === 'number' ? trimNumber(value.amount) : '';
  if (value.type === 'from') return typeof value.amount === 'number' ? `${t('importPricePrefixFrom')}${trimNumber(value.amount)}` : '';
  if (value.type === 'range') {
    return typeof value.min === 'number' && typeof value.max === 'number'
      ? `${trimNumber(value.min)}-${trimNumber(value.max)}`
      : '';
  }

  return '';
}

/**
 * Field lists are never hand-duplicated here — they're derived from the backend's
 * OnboardingFieldSchema (one entry per DTO::fieldKinds(), keyed exactly like
 * DTO::factMap()), so the review screen can never omit a field the backend requires a
 * decision for, or show one it doesn't know about. labelKey is derived from the key
 * itself (snake_case -> PascalCase) to match this project's `importField<Key>` i18n
 * convention — add the translation, not a frontend field-list entry, for a new field.
 */
function toFieldConfigs(schema: Record<string, string>): FieldConfig[] {
  return Object.entries(schema).map(([key, kind]) => ({
    key,
    kind: kind as FieldKind,
    labelKey: `importField${key.split('_').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('')}`,
  }));
}

function factValueToText(fact: ImportedFact): string {
  if (!fact || fact.value === null || fact.value === undefined) return '';
  if (typeof fact.value === 'boolean') return fact.value ? 'true' : 'false';

  return String(fact.value);
}

function emptyHours(): Record<string, string> {
  return DAY_KEYS.reduce<Record<string, string>>((acc, day) => ({ ...acc, [day]: '' }), {});
}

export function ImportReviewStep({
  result,
  fieldSchema,
  factDecisions,
  onConfirmFact,
  onConfirmAllFacts,
  onCorrectField,
  exclusions,
  onToggleExclude,
  onAddEntity,
  failedConditions,
  missingRequiredPaths,
  submitting,
  onConfirm,
}: {
  result: NormalizedExtractionResult;
  fieldSchema: OnboardingFieldSchema;
  factDecisions: Record<string, FactDecision>;
  onConfirmFact: (path: string) => void;
  onConfirmAllFacts: (paths: string[]) => void;
  onCorrectField: (path: string, value: unknown) => Promise<void>;
  exclusions: ExclusionState;
  onToggleExclude: (collection: keyof ExclusionState, fingerprint: string) => void;
  onAddEntity: (collection: keyof ExclusionState, fields: Record<string, unknown>) => Promise<void>;
  failedConditions: string[] | null;
  missingRequiredPaths: string[];
  submitting: boolean;
  onConfirm: () => void;
}) {
  const t = useT();

  const businessFields = toFieldConfigs(fieldSchema.business);
  const contactFields = toFieldConfigs(fieldSchema.contact);
  const locationFields = toFieldConfigs(fieldSchema.locations);
  const serviceFields = toFieldConfigs(fieldSchema.services);
  const staffFields = toFieldConfigs(fieldSchema.staff);
  const faqFields = toFieldConfigs(fieldSchema.faq);
  const policyFields = toFieldConfigs(fieldSchema.policies);

  // Every location currently in the draft (minus ones the user excluded — those won't
  // become real Location records), by name — the fixed set of checkboxes a service's
  // "locatii asociate" field picks from, instead of a freeform comma-separated input.
  const locationOptions = result.locations
    .filter((location) => {
      const fingerprint = location.fingerprint ?? '';

      return !(fingerprint !== '' && exclusions.locations.has(fingerprint));
    })
    .map((location) => (location.name as ImportedFact | undefined)?.value)
    .filter((value): value is string => typeof value === 'string' && value.trim() !== '');

  // With a single location there's nothing to choose between — the app itself already
  // hides this picker in that case (BranchPicker, resources/js/Pages/Dashboard/Index.tsx)
  // rather than showing a one-item checkbox list. Mirrors that here.
  const serviceFieldsForReview = locationOptions.length > 1
    ? serviceFields
    : serviceFields.filter((field) => field.kind !== 'location_checklist');

  const identityFacts = [
    ...businessFields.map((field) => ({ fact: result.business?.[field.key] ?? null, path: `business.${field.key}` })),
    ...contactFields.map((field) => ({ fact: result.contact?.[field.key] ?? null, path: `contact.${field.key}` })),
  ];
  const identityStatus = computeSectionReviewStatus(identityFacts, factDecisions);
  const identityPendingConfirmAllPaths = identityFacts
    .filter(({ fact, path }) => Boolean(fact?.requires_confirmation) && factDecisions[path]?.decision !== 'confirmed')
    .map(({ path }) => path);

  // Every field still needing a decision across every section (identity + all entity
  // collections, minus excluded entities) — powers the single global "confirm all"
  // action below, so the user isn't required to open and bulk-confirm all 6 sections
  // individually to get past the review step.
  function pendingPathsForCollection(
    collection: keyof ExclusionState,
    entities: EntityRecord[],
    fields: FieldConfig[],
  ): string[] {
    const excluded = exclusions[collection];

    return entities.flatMap((entity) => {
      const fingerprint = entity.fingerprint ?? '';
      if (fingerprint !== '' && excluded.has(fingerprint)) return [];

      return fields.flatMap((field) => {
        const fact = (entity[field.key] as ImportedFact) ?? null;
        const path = `${collection}.${fingerprint}.${field.key}`;

        return fact?.requires_confirmation && factDecisions[path]?.decision !== 'confirmed' ? [path] : [];
      });
    });
  }

  const allPendingConfirmAllPaths = [
    ...identityPendingConfirmAllPaths,
    ...pendingPathsForCollection('locations', result.locations, locationFields),
    ...pendingPathsForCollection('services', result.services, serviceFieldsForReview),
    ...pendingPathsForCollection('staff', result.staff, staffFields),
    ...pendingPathsForCollection('faq', result.faq, faqFields),
    ...pendingPathsForCollection('policies', result.policies, policyFields),
  ];

  return (
    <div className="mx-auto max-w-3xl space-y-6 pb-24">
      <div>
        <h1 className="text-2xl font-bold app-text">{t('importReviewTitle')}</h1>
        <p className="mt-2 text-sm app-text-muted">{t('importReviewHelper')}</p>
      </div>

      {allPendingConfirmAllPaths.length > 0 && (
        <Card className="border-amber-200 bg-amber-50 p-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-sm font-bold text-amber-900">{t('importConfirmAllGlobalLabel')}</p>
              <p className="mt-1 text-xs text-amber-800">{t('importConfirmAllGlobalWarning')}</p>
            </div>
            <SecondaryButton type="button" onClick={() => onConfirmAllFacts(allPendingConfirmAllPaths)}>
              {t('importConfirmAllGlobalButton', { count: allPendingConfirmAllPaths.length })}
            </SecondaryButton>
          </div>
        </Card>
      )}

      {failedConditions && failedConditions.length > 0 && (
        <Card className="border-red-200 bg-red-50 p-4">
          <ul className="list-inside list-disc space-y-1 text-sm font-medium text-red-700">
            {failedConditions.includes('business_name_missing') && <li>{t('importIncompleteBusinessName')}</li>}
            {failedConditions.includes('no_location_and_no_customer_service_area') && <li>{t('importIncompleteNoLocation')}</li>}
            {failedConditions.includes('no_services') && <li>{t('importIncompleteNoServices')}</li>}
          </ul>
        </Card>
      )}

      {missingRequiredPaths.length > 0 && (
        <Card className="border-amber-200 bg-amber-50 p-4">
          <p className="text-sm font-medium text-amber-800">{t('importMissingDecisions')}</p>
        </Card>
      )}

      <Section
        title={t('importSectionIdentity')}
        status={identityStatus}
        pendingConfirmAllPaths={identityPendingConfirmAllPaths}
        onConfirmAllFacts={onConfirmAllFacts}
      >
        {businessFields.map((field) => (
          <FactRow
            key={field.key}
            path={`business.${field.key}`}
            field={field}
            fact={result.business?.[field.key] ?? null}
            decision={factDecisions[`business.${field.key}`]}
            onConfirm={onConfirmFact}
            onCorrect={onCorrectField}
          />
        ))}
        {contactFields.map((field) => (
          <FactRow
            key={field.key}
            path={`contact.${field.key}`}
            field={field}
            fact={result.contact?.[field.key] ?? null}
            decision={factDecisions[`contact.${field.key}`]}
            onConfirm={onConfirmFact}
            onCorrect={onCorrectField}
          />
        ))}
      </Section>

      <EntitySection
        title={t('importSectionLocations')}
        collection="locations"
        entities={result.locations}
        fields={locationFields}
        exclusions={exclusions.locations}
        factDecisions={factDecisions}
        onConfirmFact={onConfirmFact}
        onConfirmAllFacts={onConfirmAllFacts}
        onCorrectField={onCorrectField}
        onToggleExclude={onToggleExclude}
        onAddEntity={onAddEntity}
        addLabel={t('importAddLocation')}
      />

      <EntitySection
        title={t('importSectionServices')}
        collection="services"
        entities={result.services}
        fields={serviceFieldsForReview}
        exclusions={exclusions.services}
        factDecisions={factDecisions}
        onConfirmFact={onConfirmFact}
        onConfirmAllFacts={onConfirmAllFacts}
        onCorrectField={onCorrectField}
        onToggleExclude={onToggleExclude}
        onAddEntity={onAddEntity}
        addLabel={t('importAddService')}
        locationOptions={locationOptions}
      />

      <EntitySection
        title={t('importSectionStaff')}
        collection="staff"
        entities={result.staff}
        fields={staffFields}
        exclusions={exclusions.staff}
        factDecisions={factDecisions}
        onConfirmFact={onConfirmFact}
        onConfirmAllFacts={onConfirmAllFacts}
        onCorrectField={onCorrectField}
        onToggleExclude={onToggleExclude}
        onAddEntity={onAddEntity}
        addLabel={t('importAddStaff')}
      />

      <EntitySection
        title={t('importSectionFaq')}
        collection="faq"
        entities={result.faq}
        fields={faqFields}
        exclusions={exclusions.faq}
        factDecisions={factDecisions}
        onConfirmFact={onConfirmFact}
        onConfirmAllFacts={onConfirmAllFacts}
        onCorrectField={onCorrectField}
        onToggleExclude={onToggleExclude}
        onAddEntity={onAddEntity}
        addLabel={t('importAddFaq')}
      />

      <EntitySection
        title={t('importSectionPolicies')}
        collection="policies"
        entities={result.policies}
        fields={policyFields}
        exclusions={exclusions.policies}
        factDecisions={factDecisions}
        onConfirmFact={onConfirmFact}
        onConfirmAllFacts={onConfirmAllFacts}
        onCorrectField={onCorrectField}
        onToggleExclude={onToggleExclude}
        onAddEntity={onAddEntity}
        addLabel={t('importAddPolicy')}
      />

      <div className="fixed inset-x-0 bottom-0 border-t app-panel p-4">
        <div className="mx-auto flex max-w-3xl justify-center sm:justify-end">
          <Button onClick={onConfirm} disabled={submitting}>{t('importConfirmAndFinish')}</Button>
        </div>
      </div>
    </div>
  );
}

function Section({
  title,
  status,
  pendingConfirmAllPaths,
  onConfirmAllFacts,
  children,
}: {
  title: string;
  status: SectionReviewStatus;
  pendingConfirmAllPaths: string[];
  onConfirmAllFacts: (paths: string[]) => void;
  children: ReactNode;
}) {
  const t = useT();
  const [expanded, setExpanded] = useState(false);

  return (
    <Card className="p-6">
      <div className="mb-4 flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
        <div className="flex min-w-0 flex-col items-center gap-2 sm:flex-row">
          <h2 className="text-lg font-bold app-text">{title}</h2>
          <SectionStatusPill status={status} />
        </div>
        <CollapseCaret expanded={expanded} onToggle={() => setExpanded((value) => !value)} />
      </div>
      {expanded && (
        <>
          {status === 'needs_review' && (
            <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
              <label className="flex items-center gap-2 text-sm font-medium text-amber-900">
                <input type="checkbox" checked={false} onChange={() => onConfirmAllFacts(pendingConfirmAllPaths)} />
                {t('importConfirmAllLabel')}
              </label>
              <p className="mt-1 text-xs text-amber-800">{t('importConfirmAllWarning')}</p>
            </div>
          )}
          <div className="space-y-4">{children}</div>
        </>
      )}
    </Card>
  );
}

function CollapseCaret({ expanded, onToggle }: { expanded: boolean; onToggle: () => void }) {
  const t = useT();

  return (
    <button
      type="button"
      onClick={onToggle}
      aria-label={expanded ? t('importCollapseSection') : t('importExpandSection')}
      className="rounded p-1 app-text-soft hover:app-text"
    >
      <ChevronDown className={`h-5 w-5 transition-transform ${expanded ? '' : '-rotate-90'}`} />
    </button>
  );
}

function EntitySection({
  title,
  collection,
  entities,
  fields,
  exclusions,
  factDecisions,
  onConfirmFact,
  onConfirmAllFacts,
  onCorrectField,
  onToggleExclude,
  onAddEntity,
  addLabel,
  locationOptions,
}: {
  title: string;
  collection: keyof ExclusionState;
  entities: EntityRecord[];
  fields: FieldConfig[];
  exclusions: Set<string>;
  factDecisions: Record<string, FactDecision>;
  onConfirmFact: (path: string) => void;
  onConfirmAllFacts?: (paths: string[]) => void;
  onCorrectField: (path: string, value: unknown) => Promise<void>;
  onToggleExclude: (collection: keyof ExclusionState, fingerprint: string) => void;
  onAddEntity: (collection: keyof ExclusionState, fields: Record<string, unknown>) => Promise<void>;
  addLabel: string;
  locationOptions?: string[];
}) {
  const t = useT();
  const [adding, setAdding] = useState(false);
  const [expanded, setExpanded] = useState(false);

  const activeEntities = entities.filter((entity) => {
    const fingerprint = entity.fingerprint ?? '';

    return !(fingerprint !== '' && exclusions.has(fingerprint));
  });

  const activeFacts = activeEntities.flatMap((entity) => {
    const fingerprint = entity.fingerprint ?? '';

    return fields.map((field) => ({
      fact: (entity[field.key] as ImportedFact) ?? null,
      path: `${collection}.${fingerprint}.${field.key}`,
    }));
  });

  const status: SectionReviewStatus = activeEntities.length === 0
    ? 'none'
    : computeSectionReviewStatus(activeFacts, factDecisions);

  const pendingConfirmAllPaths: string[] = [];
  let entriesNeedingConfirmation = 0;

  if (onConfirmAllFacts) {
    entities.forEach((entity) => {
      const fingerprint = entity.fingerprint ?? '';
      if (fingerprint !== '' && exclusions.has(fingerprint)) return;

      fields.forEach((field) => {
        const fact = (entity[field.key] as ImportedFact) ?? null;
        if (!fact?.requires_confirmation) return;

        entriesNeedingConfirmation += 1;
        const path = `${collection}.${fingerprint}.${field.key}`;
        if (!factDecisions[path]) pendingConfirmAllPaths.push(path);
      });
    });
  }

  const allConfirmed = entriesNeedingConfirmation > 0 && pendingConfirmAllPaths.length === 0;

  return (
    <Card className="p-6">
      <div className="mb-4 flex flex-col items-center gap-3 sm:flex-row sm:justify-between">
        <div className="flex min-w-0 flex-col items-center gap-2 sm:flex-row">
          <h2 className="text-lg font-bold app-text">{title}</h2>
          <SectionStatusPill status={status} />
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <SecondaryButton type="button" onClick={() => setAdding((value) => !value)}>{addLabel}</SecondaryButton>
          <CollapseCaret expanded={expanded} onToggle={() => setExpanded((value) => !value)} />
        </div>
      </div>

      {expanded && (
        <>
          {onConfirmAllFacts && entriesNeedingConfirmation > 0 && (
            <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
              <label className="flex items-center gap-2 text-sm font-medium text-amber-900">
                <input
                  type="checkbox"
                  checked={allConfirmed}
                  disabled={allConfirmed}
                  onChange={() => onConfirmAllFacts(pendingConfirmAllPaths)}
                />
                {t('importConfirmAllLabel')}
              </label>
              <p className="mt-1 text-xs text-amber-800">{t('importConfirmAllWarning')}</p>
            </div>
          )}

          {entities.length === 0 && !adding && <p className="text-sm app-text-muted">{t('importNoEntries')}</p>}

          <div className="space-y-4">
            {entities.map((entity) => {
              const fingerprint = entity.fingerprint ?? '';
              const isExcluded = fingerprint !== '' && exclusions.has(fingerprint);

              return (
                <div key={fingerprint || JSON.stringify(entity)} className={`rounded-lg border p-4 app-border ${isExcluded ? 'opacity-50' : ''}`}>
                  <div className="mb-3 flex items-center justify-between gap-3">
                    {isExcluded && <Badge tone="slate">{t('importExcluded')}</Badge>}
                    <div className="ml-auto">
                      <button
                        type="button"
                        onClick={() => fingerprint && onToggleExclude(collection, fingerprint)}
                        className="text-xs font-bold text-red-600 hover:underline"
                      >
                        {isExcluded ? t('importUndoExclude') : t('importExcludeEntry')}
                      </button>
                    </div>
                  </div>

                  {!isExcluded && (
                    <div className="space-y-3">
                      {fields.map((field) => (
                        <FactRow
                          key={field.key}
                          path={`${collection}.${fingerprint}.${field.key}`}
                          field={field}
                          fact={(entity[field.key] as ImportedFact) ?? null}
                          decision={factDecisions[`${collection}.${fingerprint}.${field.key}`]}
                          onConfirm={onConfirmFact}
                          onCorrect={onCorrectField}
                          options={field.kind === 'location_checklist' ? locationOptions : undefined}
                        />
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>

          {adding && (
            <AddEntityForm
              fields={fields}
              locationOptions={locationOptions}
              onCancel={() => setAdding(false)}
              onSave={async (values) => {
                await onAddEntity(collection, values);
                setAdding(false);
              }}
            />
          )}
        </>
      )}
    </Card>
  );
}

function AddEntityForm({
  fields,
  locationOptions,
  onCancel,
  onSave,
}: {
  fields: FieldConfig[];
  locationOptions?: string[];
  onCancel: () => void;
  onSave: (values: Record<string, unknown>) => Promise<void>;
}) {
  const t = useT();
  const [values, setValues] = useState<Record<string, unknown>>({});
  const [saving, setSaving] = useState(false);

  function setField(key: string, value: unknown) {
    setValues((current) => ({ ...current, [key]: value }));
  }

  async function handleSave() {
    setSaving(true);
    try {
      await onSave(values);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="mt-4 space-y-3 rounded-lg border p-4 app-border app-panel-soft">
      {fields.map((field) => (
        <FieldInput
          key={field.key}
          label={t(field.labelKey)}
          kind={field.kind}
          value={values[field.key]}
          onChange={(value) => setField(field.key, value)}
          options={field.kind === 'location_checklist' ? locationOptions : undefined}
        />
      ))}
      <div className="flex justify-end gap-2 pt-2">
        <SecondaryButton type="button" onClick={onCancel} disabled={saving}>{t('importCancelEdit')}</SecondaryButton>
        <Button type="button" onClick={handleSave} disabled={saving}>{t('importSaveValue')}</Button>
      </div>
    </div>
  );
}

function FactRow({
  path,
  field,
  fact,
  decision,
  onConfirm,
  onCorrect,
  options,
}: {
  path: string;
  field: FieldConfig;
  fact: ImportedFact;
  decision: FactDecision | undefined;
  onConfirm: (path: string) => void;
  onCorrect: (path: string, value: unknown) => Promise<void>;
  options?: string[];
}) {
  const t = useT();
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [draftValue, setDraftValue] = useState<unknown>(fact?.value ?? (field.kind === 'hours' ? emptyHours() : ''));

  const isConfirmedLocally = decision?.decision === 'confirmed';
  const needsReview = Boolean(fact?.requires_confirmation) && !isConfirmedLocally;

  async function handleSave() {
    setSaving(true);
    try {
      // The social_links editor keeps one input per item, including a blank one added
      // via "+" that the user hasn't filled in yet (or emptied out to delete) — trim
      // and drop those before persisting, same as the comma-split 'list' kind already
      // does on every keystroke.
      const valueToSave = Array.isArray(draftValue)
        ? draftValue.map((item) => (typeof item === 'string' ? item.trim() : item)).filter((item) => item !== '')
        : draftValue;

      await onCorrect(path, valueToSave);
      setEditing(false);
    } finally {
      setSaving(false);
    }
  }

  if (editing) {
    return (
      <div className="space-y-2">
        <Field label={t(field.labelKey)}>
          <FieldInput kind={field.kind} value={draftValue} onChange={setDraftValue} options={options} />
        </Field>
        <div className="flex gap-2">
          <Button type="button" onClick={handleSave} disabled={saving}>{t('importSaveValue')}</Button>
          <SecondaryButton type="button" onClick={() => setEditing(false)} disabled={saving}>{t('importCancelEdit')}</SecondaryButton>
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2 border-b pb-3 app-border last:border-b-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <p className="text-xs font-bold uppercase tracking-wide app-text-muted">{t(field.labelKey)}</p>
        <p className="mt-0.5 truncate text-sm app-text">{displayFactValue(field, fact, t) || '—'}</p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {needsReview && <Badge tone="amber">{t('importNeedsReview')}</Badge>}
        {fact?.requires_confirmation && (
          isConfirmedLocally ? (
            <span className="text-xs font-bold text-emerald-600">✓ {t('importValueConfirmed')}</span>
          ) : (
            <button type="button" onClick={() => onConfirm(path)} className="text-xs font-bold text-indigo-600 hover:underline">
              {t('importConfirmValue')}
            </button>
          )
        )}
        <button
          type="button"
          onClick={() => { setDraftValue(fact?.value ?? (field.kind === 'hours' ? emptyHours() : '')); setEditing(true); }}
          className="text-xs font-bold app-text-soft hover:underline"
        >
          {t('importEditValue')}
        </button>
      </div>
    </div>
  );
}

function displayFactValue(field: FieldConfig, fact: ImportedFact, t: (key: string) => string): string {
  if (!fact) return '';

  if (field.kind === 'boolean') return fact.value ? '✓' : '';
  if (field.kind === 'hours' && fact.value && typeof fact.value === 'object') {
    return DAY_KEYS
      .map((day) => (fact.value as Record<string, string>)[day])
      .filter((value) => value && value !== 'Inchis')
      .join(', ');
  }
  if ((field.kind === 'list' || field.kind === 'social_links' || field.kind === 'location_checklist') && Array.isArray(fact.value)) {
    return fact.value.join(', ');
  }
  if (field.kind === 'price') return formatPriceValue(fact.value, t);

  return factValueToText(fact);
}

function FieldInput({
  label,
  kind,
  value,
  onChange,
  options,
}: {
  label?: string;
  kind: FieldKind;
  value: unknown;
  onChange: (value: unknown) => void;
  options?: string[];
}) {
  const t = useT();

  if (kind === 'boolean') {
    return (
      <label className="flex items-center gap-2 text-sm app-text">
        <input type="checkbox" checked={Boolean(value)} onChange={(event) => onChange(event.target.checked)} />
        {label}
      </label>
    );
  }

  if (kind === 'hours') {
    const hours = (value && typeof value === 'object' ? value : emptyHours()) as Record<string, string>;

    return (
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        {DAY_KEYS.map((day) => (
          <div key={day} className="flex items-center gap-2">
            <span className="w-10 shrink-0 text-xs font-bold app-text-muted">{t(DAY_LABEL_KEYS[day]).slice(0, 3)}</span>
            <Input
              type="text"
              placeholder="09:00 - 18:00"
              value={hours[day] ?? ''}
              onChange={(event) => onChange({ ...hours, [day]: event.target.value })}
            />
          </div>
        ))}
      </div>
    );
  }

  if (kind === 'multiline') {
    return (
      <textarea
        className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none app-panel app-text"
        rows={3}
        value={typeof value === 'string' ? value : ''}
        onChange={(event) => onChange(event.target.value)}
      />
    );
  }

  if (kind === 'list') {
    const text = Array.isArray(value) ? value.join(', ') : (typeof value === 'string' ? value : '');

    return (
      <Input
        type="text"
        value={text}
        onChange={(event) => onChange(event.target.value.split(',').map((item) => item.trim()).filter(Boolean))}
      />
    );
  }

  if (kind === 'location_checklist') {
    const selected = Array.isArray(value) ? (value as unknown[]).filter((item): item is string => typeof item === 'string') : [];
    const normalize = (name: string) => name.trim().toLowerCase();
    const selectedNormalized = selected.map(normalize);

    function toggle(name: string) {
      onChange(
        selectedNormalized.includes(normalize(name))
          ? selected.filter((item) => normalize(item) !== normalize(name))
          : [...selected, name]
      );
    }

    if (!options || options.length === 0) {
      return <p className="text-sm app-text-muted">{t('importNoLocationsAvailable')}</p>;
    }

    const allSelected = options.every((name) => selectedNormalized.includes(normalize(name)));

    function toggleAll() {
      onChange(allSelected ? [] : options);
    }

    return (
      <div className="space-y-2">
        {options.length > 1 && (
          <label className="flex items-center gap-2 border-b pb-2 text-sm font-bold app-text app-border">
            <input type="checkbox" checked={allSelected} onChange={toggleAll} />
            {t('importSelectAllLocations')}
          </label>
        )}
        {options.map((name) => (
          <label key={name} className="flex items-center gap-2 text-sm app-text">
            <input type="checkbox" checked={selectedNormalized.includes(normalize(name))} onChange={() => toggle(name)} />
            {name}
          </label>
        ))}
      </div>
    );
  }

  if (kind === 'social_links') {
    const items = Array.isArray(value) ? (value as unknown[]).map((item) => (typeof item === 'string' ? item : '')) : [];

    function updateItem(index: number, next: string) {
      const nextItems = [...items];
      nextItems[index] = next;
      onChange(nextItems);
    }

    function removeItem(index: number) {
      onChange(items.filter((_, i) => i !== index));
    }

    return (
      <div className="space-y-2">
        {items.map((item, index) => (
          <div key={index} className="flex items-center gap-2">
            <Input
              type="text"
              value={item}
              placeholder="https://..."
              onChange={(event) => updateItem(index, event.target.value)}
            />
            <button
              type="button"
              onClick={() => removeItem(index)}
              aria-label={t('importRemoveSocialLink')}
              className="shrink-0 text-slate-400 transition hover:text-red-500"
            >
              <Trash2 className="h-4 w-4" />
            </button>
          </div>
        ))}
        <button
          type="button"
          onClick={() => onChange([...items, ''])}
          className="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-600 hover:underline"
        >
          <Plus className="h-3.5 w-3.5" /> {t('importAddSocialLink')}
        </button>
      </div>
    );
  }

  if (kind === 'price') {
    const price = isPriceValue(value) ? value : { type: 'fixed' as const };

    return (
      <div className="space-y-2">
        <select
          className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none app-panel app-text"
          value={price.type}
          onChange={(event) => {
            const nextType = event.target.value as PriceValue['type'];
            onChange(nextType === 'range' ? { type: 'range' } : { type: nextType });
          }}
        >
          <option value="fixed">{t('importPriceTypeFixed')}</option>
          <option value="from">{t('importPriceTypeFrom')}</option>
          <option value="range">{t('importPriceTypeRange')}</option>
        </select>

        {price.type === 'range' ? (
          <div className="flex items-center gap-2">
            <Input
              type="number"
              placeholder={t('importPriceMin')}
              value={price.min ?? ''}
              onChange={(event) => onChange({ ...price, min: event.target.value === '' ? undefined : Number(event.target.value) })}
            />
            <span className="app-text-muted">–</span>
            <Input
              type="number"
              placeholder={t('importPriceMax')}
              value={price.max ?? ''}
              onChange={(event) => onChange({ ...price, max: event.target.value === '' ? undefined : Number(event.target.value) })}
            />
          </div>
        ) : (
          <Input
            type="number"
            placeholder={t('importPriceAmount')}
            value={price.amount ?? ''}
            onChange={(event) => onChange({ ...price, amount: event.target.value === '' ? undefined : Number(event.target.value) })}
          />
        )}
      </div>
    );
  }

  return (
    <Input
      type={kind === 'number' ? 'number' : 'text'}
      value={typeof value === 'string' || typeof value === 'number' ? value : ''}
      onChange={(event) => onChange(kind === 'number' ? Number(event.target.value) : event.target.value)}
    />
  );
}
