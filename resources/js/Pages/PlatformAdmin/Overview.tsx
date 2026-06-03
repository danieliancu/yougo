import { Head } from '@inertiajs/react';
import { BusinessTable, Metric, PageHeader, Panel, PhoneAiPlannedNotice, PlatformAdminLayout } from './Components';

type Props = {
  payload: Record<string, any>;
};

export default function Overview({ payload }: Props) {
  const totals = payload.totals ?? {};
  const metrics = [
    ['Total businesses', totals.businesses],
    ['Active businesses', totals.active],
    ['WhatsApp active', totals.whatsapp_active],
    ['WhatsApp requested', totals.whatsapp_requested],
    ['WhatsApp messages this month', totals.whatsapp_messages],
    ['AI bookings this month', totals.ai_bookings],
    ['Website chat conversations this month', totals.website_chat_conversations],
    ['Issues', Object.values(payload.issue_summary ?? {}).reduce((total: number, value) => total + Number(value ?? 0), 0)],
  ];
  const issues = payload.issue_summary ?? {};

  return (
    <PlatformAdminLayout page="overview">
      <Head title="Platform Admin" />
      <PageHeader title="Overview" subtitle="Operator snapshot for businesses, WhatsApp onboarding, usage, and operational issues." />
      <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map(([label, value]) => (
          <Metric key={String(label)} label={String(label)} value={value ?? 0} />
        ))}
      </div>
      <Panel title="Recent businesses" className="mt-8">
        <BusinessTable items={payload.recent_businesses ?? []} />
      </Panel>
      <Panel title="Issue summary" className="mt-8">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {Object.entries(issues).map(([label, value]) => (
            <div key={label} className="rounded-xl border border-slate-100 bg-slate-50 p-4">
              <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label.replaceAll('_', ' ')}</p>
              <p className="mt-2 text-2xl font-bold text-slate-950">{String(value ?? 0)}</p>
            </div>
          ))}
        </div>
      </Panel>
      <PhoneAiPlannedNotice />
    </PlatformAdminLayout>
  );
}
