import { useEffect, useState } from 'react';
import { Button, Card, SecondaryButton } from '@/Components/Ui';
import { useT } from '@/i18n';
import { Draft } from '../types';

const ROTATING_MESSAGE_KEYS = ['importProgressMessage1', 'importProgressMessage2', 'importProgressMessage3', 'importProgressMessage4'];

const FAILURE_MESSAGE_KEYS: Record<string, string> = {
  source_unreachable: 'importErrorSourceUnreachable',
  source_unsupported: 'importErrorSourceUnsupported',
  insufficient_public_content: 'importErrorInsufficientContent',
  source_blocked: 'importErrorSourceBlocked',
  source_requires_authentication: 'importErrorRequiresAuthentication',
};

/**
 * There's no live backend signal to drive this: page/AI-call counts are only written
 * once, when the job finishes (see OnboardingDraftPresenter::analysisProgress) — so a
 * bar driven by real counts would sit at 0% for the entire run, then jump to 100%.
 * Instead this is time-based off `started_at` (already sent to the client), calibrated
 * against real observed run durations this session (roughly 100-260s end to end,
 * dominated by AI analysis, not the initial page fetch). It grows fast early, slows
 * down, and is capped below 100% for as long as the draft is still running, so it
 * never promises a completion it can't back up — the parent step transitions away once
 * the draft is actually done.
 */
const PROGRESS_HALF_LIFE_SECONDS = 90;
const PROGRESS_CAP_PERCENT = 92;
const PROGRESS_STARTING_PERCENT = 4;

function estimateProgressPercent(elapsedSeconds: number): number {
  const raw = (elapsedSeconds / (elapsedSeconds + PROGRESS_HALF_LIFE_SECONDS)) * 100;

  return Math.min(PROGRESS_CAP_PERCENT, Math.max(PROGRESS_STARTING_PERCENT, Math.round(raw)));
}

export function ImportProgressStep({
  draft,
  onRetry,
  onChangeAddress,
  retrying,
}: {
  draft: Draft;
  onRetry: () => void;
  onChangeAddress: () => void;
  retrying: boolean;
}) {
  const t = useT();
  const [messageIndex, setMessageIndex] = useState(0);
  const [nowMs, setNowMs] = useState(() => Date.now());

  const isRunning = draft.status === 'analysing' || draft.status === 'pending';

  useEffect(() => {
    if (!isRunning) return;

    const interval = setInterval(() => {
      setMessageIndex((index) => (index + 1) % ROTATING_MESSAGE_KEYS.length);
    }, 3500);

    return () => clearInterval(interval);
  }, [isRunning]);

  useEffect(() => {
    if (!isRunning) return;

    const interval = setInterval(() => setNowMs(Date.now()), 1000);

    return () => clearInterval(interval);
  }, [isRunning]);

  if (draft.status === 'analysis_failed') {
    const failureKey = (draft.failure_code && FAILURE_MESSAGE_KEYS[draft.failure_code]) || 'importErrorGeneric';

    return (
      <Card className="mx-auto max-w-xl p-8 text-center">
        <p className="text-base font-bold text-red-600">{t(failureKey)}</p>
        <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
          <Button onClick={onRetry} disabled={retrying}>{t('importRetry')}</Button>
          <SecondaryButton onClick={onChangeAddress}>{t('importChangeAddress')}</SecondaryButton>
        </div>
      </Card>
    );
  }

  const pagesProcessed = draft.analysis_progress?.pages_processed ?? null;
  const startedAtMs = draft.started_at ? new Date(draft.started_at).getTime() : null;
  const elapsedSeconds = startedAtMs !== null ? Math.max(0, (nowMs - startedAtMs) / 1000) : 0;
  const progressPercent = startedAtMs !== null ? estimateProgressPercent(elapsedSeconds) : PROGRESS_STARTING_PERCENT;

  return (
    <Card className="mx-auto max-w-xl p-8 text-center">
      <div className="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-indigo-200 border-t-indigo-600" />
      <h1 className="mt-6 text-xl font-bold app-text">{t('importProgressTitle')}</h1>
      <p className="mt-2 text-sm app-text-muted">{t(ROTATING_MESSAGE_KEYS[messageIndex])}</p>

      <div className="mx-auto mt-5 h-2 w-full max-w-xs overflow-hidden rounded-full bg-slate-100 app-panel-soft">
        <div
          className="h-full rounded-full bg-indigo-600 transition-[width] duration-700 ease-out"
          style={{ width: `${progressPercent}%` }}
        />
      </div>
      <p className="mt-2 text-xs app-text-muted">{t('importProgressPercent', { percent: progressPercent })}</p>

      {pagesProcessed !== null && pagesProcessed > 0 && (
        <p className="mt-1 text-xs app-text-muted">{t('importProgressPagesAnalyzed', { count: pagesProcessed })}</p>
      )}
    </Card>
  );
}
