import { useState } from 'react';
import { Button, Card } from '@/Components/Ui';
import { useT } from '@/i18n';

export type IndustryReview = {
  conflict: boolean;
  detected_business_type: string | null;
  selected_business_type: string | null;
  recommended_primary_capability: string | null;
  recommended_secondary_capabilities: string[];
};

export function CapabilitiesConfirmStep({
  review,
  submitting,
  onConfirm,
}: {
  review: IndustryReview;
  submitting: boolean;
  onConfirm: (primary: string, secondary: string[]) => void;
}) {
  const t = useT();
  const recommendedPrimary = review.recommended_primary_capability ?? 'appointment';
  const otherCapability = recommendedPrimary === 'request' ? 'appointment' : 'request';
  const recommendedIncludesOther = review.recommended_secondary_capabilities.includes(otherCapability);

  const [primary, setPrimary] = useState(recommendedPrimary);
  const [includeOther, setIncludeOther] = useState(recommendedIncludesOther);

  function submit() {
    const other = primary === 'request' ? 'appointment' : 'request';
    onConfirm(primary, includeOther ? [other] : []);
  }

  return (
    <Card className="mx-auto max-w-xl p-8">
      <h1 className="text-2xl font-bold app-text">{t('capabilitiesConfirmTitle')}</h1>
      <p className="mt-2 text-sm leading-6 app-text-soft">{t('capabilitiesConfirmHelper')}</p>

      {review.conflict && (
        <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          {t('capabilitiesConfirmConflictNotice')}
        </p>
      )}

      <div className="mt-6 space-y-3">
        <label className="flex cursor-pointer items-start gap-3 rounded-lg border p-4 app-border">
          <input type="radio" name="primary_capability" className="mt-1" checked={primary === 'request'} onChange={() => setPrimary('request')} />
          <span>
            <span className="block font-bold app-text">{t('capabilitiesConfirmRequestOption')}</span>
            <span className="block text-sm app-text-soft">{t('capabilitiesConfirmRequestOptionHelper')}</span>
          </span>
        </label>
        <label className="flex cursor-pointer items-start gap-3 rounded-lg border p-4 app-border">
          <input type="radio" name="primary_capability" className="mt-1" checked={primary === 'appointment'} onChange={() => setPrimary('appointment')} />
          <span>
            <span className="block font-bold app-text">{t('capabilitiesConfirmAppointmentOption')}</span>
            <span className="block text-sm app-text-soft">{t('capabilitiesConfirmAppointmentOptionHelper')}</span>
          </span>
        </label>
        <label className="flex cursor-pointer items-center gap-3 rounded-lg border p-4 app-border">
          <input type="checkbox" checked={includeOther} onChange={(event) => setIncludeOther(event.target.checked)} />
          <span className="text-sm app-text">
            {primary === 'request' ? t('capabilitiesConfirmAlsoAppointment') : t('capabilitiesConfirmAlsoRequest')}
          </span>
        </label>
      </div>

      <Button className="mt-6 w-full" disabled={submitting} onClick={submit}>
        {t('capabilitiesConfirmSubmit')}
      </Button>
    </Card>
  );
}
