import { Head } from '@inertiajs/react';
import { KeyValue, PageHeader, Panel, PhoneAiPlannedNotice, PlatformAdminLayout, UsageSummary, Warnings } from './Components';

type Props = {
  payload: Record<string, any>;
};

export default function Usage({ payload }: Props) {
  return (
    <PlatformAdminLayout page="usage">
      <Head title="Platform Admin Usage" />
      <PageHeader title="Usage" subtitle={`Current-month usage vs limits (${payload.month ?? ''}). Rows over 80% are flagged as near limit.`} />
      <div className="mb-5 rounded-2xl border border-indigo-100 bg-indigo-50 p-4 text-sm font-semibold text-indigo-800 shadow-sm shadow-slate-200/70">
        monthly_whatsapp_messages is the billable/limit row for inbound + outbound WhatsApp messages. whatsapp_conversation is analytics only.
      </div>
      <div className="space-y-4">
        {(payload.items ?? []).map((item: Record<string, any>) => (
          <Panel key={item.id} title={item.name}>
            <div className="grid gap-5 lg:grid-cols-[280px_1fr]">
              <KeyValue data={{ owner_email: item.owner_email, plan: item.plan, subscription_status: item.subscription_status, phone_ai: 'Planned' }} />
              <div>
                <UsageSummary summary={item.usage} />
                <Warnings warnings={item.warnings ?? []} />
              </div>
            </div>
          </Panel>
        ))}
      </div>
      <PhoneAiPlannedNotice />
    </PlatformAdminLayout>
  );
}
