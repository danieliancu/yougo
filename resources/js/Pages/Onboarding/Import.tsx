import { useCallback, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { useT } from '@/i18n';
import { ImportUrlStep } from './components/ImportUrlStep';
import { ImportProgressStep } from './components/ImportProgressStep';
import { ImportReviewStep } from './components/ImportReviewStep';
import { csrfHeaders, Draft, ExclusionState, FactDecision, OnboardingFieldSchema } from './types';

type PageProps = {
  salon: { id: number; name: string };
  active_draft: Draft | null;
  field_schema: OnboardingFieldSchema;
};

async function apiFetch(url: string, options: RequestInit = {}): Promise<{ ok: boolean; status: number; data: any }> {
  const response = await fetch(url, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...csrfHeaders(),
      ...(options.headers ?? {}),
    },
  });

  const data = await response.json().catch(() => ({}));

  return { ok: response.ok, status: response.status, data };
}

function emptyExclusions(): ExclusionState {
  return { locations: new Set(), services: new Set(), staff: new Set(), faq: new Set(), policies: new Set() };
}

export default function Import({ active_draft, field_schema }: PageProps) {
  const t = useT();
  const [draft, setDraft] = useState<Draft | null>(active_draft);
  const [starting, setStarting] = useState(false);
  const [retrying, setRetrying] = useState(false);
  const [urlError, setUrlError] = useState<string | null>(null);
  const [factDecisions, setFactDecisions] = useState<Record<string, FactDecision>>({});
  const [exclusions, setExclusions] = useState<ExclusionState>(emptyExclusions());
  const [missingRequiredPaths, setMissingRequiredPaths] = useState<string[]>([]);
  const [failedConditions, setFailedConditions] = useState<string[] | null>(null);
  const [confirming, setConfirming] = useState(false);
  const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const step = !draft
    ? 'url'
    : draft.status === 'review_required'
      ? 'review'
      : draft.status === 'confirmed'
        ? 'done'
        : 'progress';

  useEffect(() => {
    if (step !== 'progress' || !draft || (draft.status !== 'pending' && draft.status !== 'analysing')) {
      return;
    }

    async function poll() {
      if (document.hidden || !draft) return;

      const { ok, data } = await apiFetch(`/onboarding/import/${draft.id}`);

      if (ok) setDraft(data as Draft);
    }

    pollingRef.current = setInterval(poll, 2500);
    document.addEventListener('visibilitychange', poll);

    return () => {
      if (pollingRef.current) clearInterval(pollingRef.current);
      document.removeEventListener('visibilitychange', poll);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step, draft?.id, draft?.status]);

  useEffect(() => {
    if (step === 'done') {
      router.visit('/dashboard/overview');
    }
  }, [step]);

  const handleStartUrl = useCallback(async (url: string) => {
    setStarting(true);
    setUrlError(null);

    try {
      const { ok, data } = await apiFetch('/onboarding/import', {
        method: 'POST',
        body: JSON.stringify({ source_type: 'url', source_url: url }),
      });

      if (!ok) {
        setUrlError(typeof data.message === 'string' ? data.message : t('importErrorGeneric'));

        return;
      }

      setFactDecisions({});
      setExclusions(emptyExclusions());
      setDraft(data as Draft);
    } finally {
      setStarting(false);
    }
  }, [t]);

  const handleStartManual = useCallback(async () => {
    setStarting(true);
    setUrlError(null);

    try {
      const { ok, data } = await apiFetch('/onboarding/import', {
        method: 'POST',
        body: JSON.stringify({ source_type: 'manual' }),
      });

      if (!ok) {
        setUrlError(typeof data.message === 'string' ? data.message : t('importErrorGeneric'));

        return;
      }

      setFactDecisions({});
      setExclusions(emptyExclusions());
      setDraft(data as Draft);
    } finally {
      setStarting(false);
    }
  }, [t]);

  const handleRetry = useCallback(async () => {
    if (!draft) return;

    setRetrying(true);
    try {
      const { ok, data } = await apiFetch(`/onboarding/import/${draft.id}/retry`, { method: 'POST' });

      if (ok) setDraft(data as Draft);
    } finally {
      setRetrying(false);
    }
  }, [draft]);

  const handleChangeAddress = useCallback(() => {
    setDraft(null);
    setFactDecisions({});
    setExclusions(emptyExclusions());
  }, []);

  const handleConfirmFact = useCallback((path: string) => {
    setFactDecisions((current) => ({ ...current, [path]: { decision: 'confirmed' } }));
  }, []);

  const handleConfirmAllFacts = useCallback((paths: string[]) => {
    if (paths.length === 0) return;

    setFactDecisions((current) => {
      const next = { ...current };
      paths.forEach((path) => { next[path] = { decision: 'confirmed' }; });

      return next;
    });
  }, []);

  const handleCorrectField = useCallback(async (path: string, value: unknown) => {
    if (!draft) return;

    const { ok, data, status } = await apiFetch(`/onboarding/import/${draft.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ expected_revision: draft.revision, corrections: { [path]: { value } } }),
    });

    if (ok) {
      setDraft(data as Draft);
      setFactDecisions((current) => {
        const next = { ...current };
        delete next[path];

        return next;
      });

      return;
    }

    if (status === 409) {
      toast.error(t('importDataUpdatedNotice'));
      const refreshed = await apiFetch(`/onboarding/import/${draft.id}`);
      if (refreshed.ok) setDraft(refreshed.data as Draft);

      return;
    }

    toast.error(typeof data.message === 'string' ? data.message : t('importErrorGeneric'));
  }, [draft, t]);

  const handleAddEntity = useCallback(async (collection: keyof ExclusionState, fields: Record<string, unknown>) => {
    if (!draft) return;

    const scratchKey = `new-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const corrections: Record<string, { value: unknown }> = {};

    Object.entries(fields).forEach(([field, value]) => {
      if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) return;

      corrections[`${collection}.${scratchKey}.${field}`] = { value };
    });

    if (Object.keys(corrections).length === 0) return;

    const { ok, data, status } = await apiFetch(`/onboarding/import/${draft.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ expected_revision: draft.revision, corrections }),
    });

    if (ok) {
      setDraft(data as Draft);

      return;
    }

    if (status === 409) {
      toast.error(t('importDataUpdatedNotice'));
      const refreshed = await apiFetch(`/onboarding/import/${draft.id}`);
      if (refreshed.ok) setDraft(refreshed.data as Draft);

      return;
    }

    toast.error(typeof data.message === 'string' ? data.message : t('importErrorGeneric'));
  }, [draft, t]);

  const handleToggleExclude = useCallback((collection: keyof ExclusionState, fingerprint: string) => {
    setExclusions((current) => {
      const next = { ...current, [collection]: new Set(current[collection]) };

      if (next[collection].has(fingerprint)) {
        next[collection].delete(fingerprint);
      } else {
        next[collection].add(fingerprint);
      }

      return next;
    });
  }, []);

  const handleConfirm = useCallback(async () => {
    if (!draft) return;

    setConfirming(true);
    setMissingRequiredPaths([]);
    setFailedConditions(null);

    try {
      const facts: Record<string, FactDecision> = {};
      Object.entries(factDecisions).forEach(([path, decision]) => { facts[path] = decision; });

      const { ok, data, status } = await apiFetch(`/onboarding/import/${draft.id}/confirm`, {
        method: 'POST',
        body: JSON.stringify({
          expected_revision: draft.revision,
          facts,
          excluded_locations: Array.from(exclusions.locations),
          excluded_services: Array.from(exclusions.services),
          excluded_staff: Array.from(exclusions.staff),
          excluded_faq: Array.from(exclusions.faq),
          excluded_policies: Array.from(exclusions.policies),
        }),
      });

      if (ok) {
        toast.success(t('importSuccessToast'));
        router.visit('/dashboard/overview');

        return;
      }

      if (status === 409) {
        toast.error(t('importDataUpdatedNotice'));
        const refreshed = await apiFetch(`/onboarding/import/${draft.id}`);
        if (refreshed.ok) setDraft(refreshed.data as Draft);

        return;
      }

      if (Array.isArray(data.missing_fact_decisions)) {
        setMissingRequiredPaths(data.missing_fact_decisions);
        // Always the translated string here, never the raw backend message (English-only,
        // MissingFactDecisionsException.php) — the on-page banner already shows this same
        // t('importMissingDecisions') text, so the toast would otherwise show the Romanian
        // UI one English sentence out of step with itself.
        toast.error(t('importMissingDecisions'));

        return;
      }

      if (Array.isArray(data.failed_conditions)) {
        setFailedConditions(data.failed_conditions);

        return;
      }

      toast.error(typeof data.message === 'string' ? data.message : t('importErrorGeneric'));
    } finally {
      setConfirming(false);
    }
  }, [draft, exclusions, factDecisions, t]);

  return (
    <div className="min-h-screen p-6 app-bg">
      {step === 'url' && (
        <ImportUrlStep onSubmitUrl={handleStartUrl} onSubmitManual={handleStartManual} submitting={starting} errorMessage={urlError} />
      )}
      {step === 'progress' && draft && (
        <ImportProgressStep draft={draft} onRetry={handleRetry} onChangeAddress={handleChangeAddress} retrying={retrying} />
      )}
      {step === 'review' && draft && draft.normalized_extraction_result && (
        <ImportReviewStep
          result={draft.normalized_extraction_result}
          fieldSchema={field_schema}
          factDecisions={factDecisions}
          onConfirmFact={handleConfirmFact}
          onConfirmAllFacts={handleConfirmAllFacts}
          onCorrectField={handleCorrectField}
          exclusions={exclusions}
          onToggleExclude={handleToggleExclude}
          onAddEntity={handleAddEntity}
          failedConditions={failedConditions}
          missingRequiredPaths={missingRequiredPaths}
          submitting={confirming}
          onConfirm={handleConfirm}
        />
      )}
    </div>
  );
}
