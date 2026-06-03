import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { BusinessTable, PageHeader, Pagination, Panel, PlatformAdminLayout, Select } from './Components';

type Props = {
  payload: Record<string, any>;
};

export default function Businesses({ payload }: Props) {
  const filters = payload.filters ?? {};
  const [search, setSearch] = useState(filters.search ?? '');
  const [plan, setPlan] = useState(filters.plan ?? '');
  const [subscriptionStatus, setSubscriptionStatus] = useState(filters.subscription_status ?? '');
  const [whatsappStatus, setWhatsappStatus] = useState(filters.whatsapp_status ?? '');

  function submit(event: FormEvent) {
    event.preventDefault();
    router.get('/platform-admin/businesses', {
      search,
      plan,
      subscription_status: subscriptionStatus,
      whatsapp_status: whatsappStatus,
    }, { preserveState: true });
  }

  return (
    <PlatformAdminLayout page="businesses">
      <Head title="Platform Admin Businesses" />
      <PageHeader title="Businesses" subtitle="Search and filter customer accounts by owner, plan, subscription, and WhatsApp status." />
      <form onSubmit={submit} className="mb-6 grid gap-3 rounded-md border border-slate-800 bg-slate-900/60 p-4 md:grid-cols-4">
        <label className="md:col-span-2">
          <span className="text-xs font-bold uppercase tracking-wide text-slate-400">Business or email</span>
          <div className="mt-1 flex items-center gap-2 rounded-md border border-slate-700 bg-slate-950 px-3">
            <Search className="h-4 w-4 text-slate-500" />
            <input value={search} onChange={(event) => setSearch(event.target.value)} className="w-full bg-transparent py-2 text-sm text-slate-100 outline-none" />
          </div>
        </label>
        <Select label="Plan" value={plan} onChange={setPlan} options={['', ...Object.keys(payload.plans ?? {})]} />
        <Select label="Subscription" value={subscriptionStatus} onChange={setSubscriptionStatus} options={['', 'active', 'trialing', 'past_due', 'cancelled', 'none']} />
        <Select label="WhatsApp status" value={whatsappStatus} onChange={setWhatsappStatus} options={['', 'requested', 'active', 'failed', 'disabled', 'not_connected']} />
        <button className="rounded-md bg-indigo-500 px-4 py-2 text-sm font-black text-white hover:bg-indigo-600 md:self-end">Apply filters</button>
      </form>
      <Panel title={`Businesses (${payload.pagination?.total ?? 0})`}>
        <BusinessTable items={payload.items ?? []} />
        <Pagination pagination={payload.pagination} />
      </Panel>
    </PlatformAdminLayout>
  );
}
