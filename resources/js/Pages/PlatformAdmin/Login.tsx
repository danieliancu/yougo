import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, LockKeyhole, ShieldCheck } from 'lucide-react';
import { FormEvent } from 'react';

export default function PlatformAdminLogin() {
  const form = useForm({
    email: '',
    password: '',
    remember: false,
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    form.post('/platform-admin/login');
  }

  return (
    <div className="min-h-screen bg-slate-950 text-white">
      <Head title="YouGo Platform Admin" />
      <div className="grid min-h-screen lg:grid-cols-[460px_1fr]">
        <aside className="hidden border-r border-white/10 bg-slate-950 p-8 lg:flex lg:flex-col">
          <div className="flex items-center gap-3">
            <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-500 text-base font-black shadow-lg shadow-indigo-950/50">YG</span>
            <span>
              <span className="block text-sm font-black">YouGo Admin</span>
              <span className="block text-xs font-semibold text-slate-400">Platform operations</span>
            </span>
          </div>
          <div className="mt-auto rounded-3xl border border-white/10 bg-white/5 p-6">
            <ShieldCheck className="h-9 w-9 text-indigo-300" />
            <h2 className="mt-5 text-2xl font-black tracking-tight">Separate operator access</h2>
            <p className="mt-3 text-sm leading-6 text-slate-400">
              This area is restricted to YouGo platform operators. Business accounts continue to use the normal dashboard login.
            </p>
          </div>
        </aside>

        <main className="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6 lg:px-8">
          <section className="w-full max-w-md rounded-3xl border border-white/10 bg-white p-6 text-slate-950 shadow-2xl shadow-slate-950/40 sm:p-8">
            <div className="mb-8">
              <span className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-700">
                <LockKeyhole className="h-6 w-6" />
              </span>
              <h1 className="mt-5 text-3xl font-black tracking-tight">YouGo Platform Admin</h1>
              <p className="mt-2 text-sm font-semibold text-slate-500">Internal operator access</p>
            </div>

            <form className="space-y-5" onSubmit={submit}>
              <label className="block">
                <span className="text-xs font-black uppercase tracking-wide text-slate-500">Email</span>
                <input
                  type="email"
                  value={form.data.email}
                  onChange={(event) => form.setData('email', event.target.value)}
                  autoComplete="email"
                  className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-800 outline-none transition focus:border-indigo-300 focus:bg-white focus:ring-4 focus:ring-indigo-100"
                />
                {form.errors.email && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.email}</p>}
              </label>

              <label className="block">
                <span className="text-xs font-black uppercase tracking-wide text-slate-500">Password</span>
                <input
                  type="password"
                  value={form.data.password}
                  onChange={(event) => form.setData('password', event.target.value)}
                  autoComplete="current-password"
                  className="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-800 outline-none transition focus:border-indigo-300 focus:bg-white focus:ring-4 focus:ring-indigo-100"
                />
                {form.errors.password && <p className="mt-2 text-sm font-semibold text-red-600">{form.errors.password}</p>}
              </label>

              <label className="flex items-center gap-2 text-sm font-semibold text-slate-600">
                <input
                  type="checkbox"
                  checked={form.data.remember}
                  onChange={(event) => form.setData('remember', event.target.checked)}
                  className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                />
                Remember this admin session
              </label>

              <button
                type="submit"
                disabled={form.processing}
                className="flex h-11 w-full items-center justify-center rounded-xl bg-indigo-600 px-4 text-sm font-black text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                Sign in to Platform Admin
              </button>
            </form>

            <div className="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm font-bold">
              <Link href="/" className="inline-flex items-center gap-2 text-slate-500 hover:text-slate-800">
                <ArrowLeft className="h-4 w-4" />
                Main site
              </Link>
              <Link href="/login" className="text-indigo-700 hover:text-indigo-900">Business login</Link>
            </div>
          </section>
        </main>
      </div>
    </div>
  );
}
