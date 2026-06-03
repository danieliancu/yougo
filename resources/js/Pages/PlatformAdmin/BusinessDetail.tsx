import { Head, Link } from '@inertiajs/react';
import { CommandBox, Empty, KeyValue, PageHeader, Panel, PlatformAdminLayout, RecentActivity, SetupRequest, UsageSummary } from './Components';

type Props = {
  payload: Record<string, any>;
};

export default function BusinessDetail({ payload }: Props) {
  const business = payload.business ?? {};
  const whatsapp = payload.whatsapp;

  return (
    <PlatformAdminLayout page="business_detail">
      <Head title={`Platform Admin - ${business.name ?? 'Business'}`} />
      <PageHeader title={business.name ?? 'Business detail'} subtitle={business.owner_email ?? 'Business profile, billing, usage, WhatsApp technical details, and recent activity.'} />
      <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
        <div className="space-y-6">
          <Panel title="Business profile">
            <KeyValue data={payload.profile ?? {}} />
          </Panel>
          <Panel title="Plan and billing">
            <KeyValue data={payload.billing ?? {}} />
          </Panel>
          <Panel title="Current usage vs limits">
            <UsageSummary summary={payload.usage} />
          </Panel>
          <Panel title="Recent activity">
            <RecentActivity activity={payload.recent_activity ?? {}} />
          </Panel>
        </div>
        <div className="space-y-6">
          <Panel title="WhatsApp integration">
            {whatsapp ? (
              <>
                <KeyValue data={whatsapp} exclude={['metadata', 'setup_request']} />
                <CommandBox command={whatsapp.activation_command} />
                <Link href="/platform-admin/whatsapp-onboarding" className="mt-4 inline-flex rounded-xl bg-indigo-50 px-3 py-2 text-sm font-black text-indigo-700 hover:bg-indigo-100">
                  WhatsApp onboarding
                </Link>
              </>
            ) : (
              <Empty text="No WhatsApp integration." />
            )}
          </Panel>
          <Panel title="Latest setup request">
            {whatsapp?.setup_request ? <SetupRequest request={whatsapp.setup_request} /> : <Empty text="No setup request metadata yet." />}
          </Panel>
        </div>
      </div>
    </PlatformAdminLayout>
  );
}
