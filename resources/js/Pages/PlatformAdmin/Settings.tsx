import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { PageHeader, Panel, PlatformAdminLayout } from './Components';

export default function PlatformAdminSettings() {
  const { auth } = usePage<{ auth?: { platform_admin?: { name?: string | null; username?: string | null } | null } }>().props;
  const admin = auth?.platform_admin;
  const form = useForm({
    name: admin?.name ?? 'Platform Admin',
    username: admin?.username ?? 'admin',
    current_password: '',
    password: '',
    password_confirmation: '',
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.put('/platform-admin/settings', {
      preserveScroll: true,
      onSuccess: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
  }

  return (
    <PlatformAdminLayout page="settings">
      <Head title="Platform Admin Settings" />
      <PageHeader title="Admin Settings" subtitle="Change the dedicated Platform Admin username and password." />

      <Panel title="Platform Admin credentials" className="max-w-2xl">
        <form className="space-y-5" onSubmit={submit}>
          <Field label="Name" error={form.errors.name}>
            <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} className={inputClassName} />
          </Field>

          <Field label="Username" error={form.errors.username}>
            <input value={form.data.username} onChange={(event) => form.setData('username', event.target.value)} autoComplete="username" className={inputClassName} />
          </Field>

          <Field label="Current password" error={form.errors.current_password}>
            <input type="password" value={form.data.current_password} onChange={(event) => form.setData('current_password', event.target.value)} autoComplete="current-password" className={inputClassName} />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="New password" error={form.errors.password}>
              <input type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="new-password" className={inputClassName} />
            </Field>

            <Field label="Confirm new password" error={form.errors.password_confirmation}>
              <input type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} autoComplete="new-password" className={inputClassName} />
            </Field>
          </div>

          <button disabled={form.processing} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">
            Save admin credentials
          </button>
        </form>
      </Panel>
    </PlatformAdminLayout>
  );
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return (
    <label className="block">
      <span className="text-xs font-black uppercase tracking-wide text-slate-500">{label}</span>
      <div className="mt-1">{children}</div>
      {error && <p className="mt-2 text-sm font-semibold text-red-600">{error}</p>}
    </label>
  );
}

const inputClassName = 'h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-800 outline-none transition focus:border-indigo-300 focus:bg-white focus:ring-4 focus:ring-indigo-100';
