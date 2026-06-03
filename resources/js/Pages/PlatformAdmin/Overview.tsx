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
    ['Free plan businesses', totals.free],
    ['Paid plan businesses', totals.paid],
    ['WhatsApp activation requested', totals.whatsapp_requested],
    ['WhatsApp active', totals.whatsapp_active],
    ['WhatsApp failed', totals.whatsapp_failed],
    ['WhatsApp disabled', totals.whatsapp_disabled],
    ['WhatsApp messages this month', totals.whatsapp_messages],
    ['AI bookings this month', totals.ai_bookings],
    ['Website chat conversations this month', totals.website_chat_conversations],
    ['Phone AI', 'Planned'],
  ];
  const issues = payload.issue_summary ?? {};

  return (
    <PlatformAdminLayout page="overview">
      <Head title="Platform Admin" />
      <PageHeader title="Overview" subtitle="Operator snapshot for businesses, WhatsApp onboarding, usage, and operational issues." />
      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map(([label, value]) => (
          <Metric key={String(label)} label={String(label)} value={value ?? 0} />
        ))}
      </div>
      <Panel title="Issues summary" className="mt-8">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {Object.entries(issues).map(([label, value]) => (
            <Metric key={label} label={label.replaceAll('_', ' ')} value={value} />
          ))}
        </div>
      </Panel>
      <Panel title="Recent businesses" className="mt-8">
        <BusinessTable items={payload.recent_businesses ?? []} />
      </Panel>
      <PhoneAiPlannedNotice />
    </PlatformAdminLayout>
  );
}
