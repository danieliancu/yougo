import { FormEvent, useState } from 'react';
import { Button, Card, Field, Input, SecondaryButton } from '@/Components/Ui';
import { useT } from '@/i18n';

export function ImportUrlStep({
  onSubmitUrl,
  onSubmitManual,
  submitting,
  errorMessage,
}: {
  onSubmitUrl: (url: string) => void;
  onSubmitManual: () => void;
  submitting: boolean;
  errorMessage: string | null;
}) {
  const t = useT();
  const [url, setUrl] = useState('');
  const [validationError, setValidationError] = useState<string | null>(null);

  function normalizeTypedUrl(value: string): string {
    const trimmed = value.trim();

    if (trimmed === '' || /^https?:\/\//i.test(trimmed)) {
      return trimmed;
    }

    return `https://${trimmed}`;
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault();

    const normalized = normalizeTypedUrl(url);

    if (!normalized) {
      setValidationError(t('importUrlRequired'));

      return;
    }

    setValidationError(null);
    onSubmitUrl(normalized);
  }

  return (
    <Card className="mx-auto max-w-xl p-8">
      <h1 className="text-2xl font-bold app-text">{t('importUrlTitle')}</h1>
      <p className="mt-2 text-sm leading-6 app-text-muted">{t('importUrlHelper')}</p>

      <form onSubmit={handleSubmit} className="mt-6 space-y-4">
        <Field label={t('importUrlLabel')} error={validationError ?? undefined}>
          <Input
            type="text"
            inputMode="url"
            autoFocus
            placeholder={t('importUrlPlaceholder')}
            value={url}
            onChange={(event) => setUrl(event.target.value)}
            disabled={submitting}
          />
        </Field>

        {errorMessage && <p className="text-sm font-medium text-red-600">{errorMessage}</p>}

        <div className="flex flex-col gap-3 pt-2 sm:flex-row">
          <Button type="submit" disabled={submitting} className="sm:flex-1">
            {t('importUrlSubmit')}
          </Button>
          <SecondaryButton type="button" disabled={submitting} onClick={onSubmitManual}>
            {t('importNoWebsite')}
          </SecondaryButton>
        </div>
      </form>
    </Card>
  );
}
