import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CommandBox, KeyValue, PageHeader, Panel, PlatformAdminLayout, SetupRequest } from './Components';

type Props = {
  payload: Record<string, any>;
};

export default function WhatsappOnboarding({ payload }: Props) {
  const [status, setStatus] = useState(payload.status ?? 'requested');
  const items = payload.items ?? [];

  function changeStatus(next: string) {
    setStatus(next);
    router.get('/platform-admin/whatsapp-onboarding', { status: next }, { preserveState: true });
  }

  return (
    <PlatformAdminLayout page="whatsapp_onboarding">
      <Head title="Platform Admin WhatsApp Onboarding" />
      <PageHeader title="WhatsApp Onboarding" subtitle="Requested integrations, setup call details, Meta/account answers, and copyable activation commands." />
      <div className="mb-5 flex flex-wrap gap-2">
        {['requested', 'active', 'failed', 'disabled', 'all'].map((option) => (
          <button key={option} type="button" onClick={() => changeStatus(option)} className={`rounded-md px-3 py-2 text-sm font-bold capitalize ${status === option ? 'bg-indigo-500 text-white' : 'bg-slate-900 text-slate-300 hover:bg-slate-800'}`}>
            {option}
          </button>
        ))}
      </div>
      <div className="space-y-4">
        {items.map((item: Record<string, any>) => (
          <Panel key={item.id} title={item.business_name ?? `Salon ${item.salon_id}`}>
            <div className="grid gap-5 lg:grid-cols-[1fr_1fr_340px]">
              <KeyValue data={{
                status: item.status,
                requested_number: item.requested_number,
                display_number: item.display_number,
                owner_email: item.owner_email,
                ai_enabled: item.ai_enabled,
                requested_at: item.requested_at,
              }} />
              <SetupRequest request={item.setup_request} />
              <div>
                <h3 className="mb-2 text-sm font-black text-slate-100">Admin checklist</h3>
                <ul className="space-y-2 text-sm text-slate-300">
                  <li>Configure sender manually in Twilio or Meta.</li>
                  <li>Verify approved/live sender state.</li>
                  <li>Run activation command.</li>
                  <li>Test inbound and outbound messages.</li>
                  <li>Confirm delivery statuses.</li>
                </ul>
                <CommandBox command={item.activation_command} />
                {item.salon_id && <Link href={`/platform-admin/businesses/${item.salon_id}`} className="mt-4 inline-flex rounded-md bg-slate-800 px-3 py-2 text-sm font-bold text-slate-100 hover:bg-slate-700">View business</Link>}
              </div>
            </div>
          </Panel>
        ))}
        {items.length === 0 && <Panel title="No onboarding items">No onboarding items for this status.</Panel>}
      </div>
    </PlatformAdminLayout>
  );
}
