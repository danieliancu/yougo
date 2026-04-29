import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/Ui';
import { UserPlus } from 'lucide-react';
import { useT } from '@/i18n';
import { businessTaxonomy } from '@/data/businessTaxonomy';

export default function Register() {
  const t = useT();
  const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    business_name: '',
    business_type: '',
  });

  return (
    <AuthLayout title={t('createAccount')}>
      <h1 className="text-2xl font-bold app-text">{t('createAccount')}</h1>
      <p className="mt-2 text-sm app-text-muted">{t('registerSubtitle')}</p>

      <form className="mt-8 space-y-5" onSubmit={(event) => { event.preventDefault(); form.post('/register'); }}>
        <Field label={t('name')} error={form.errors.name}>
          <Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoComplete="name" />
        </Field>
        <Field label="Email" error={form.errors.email}>
          <Input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} autoComplete="email" />
        </Field>
        <Field label={t('password')} error={form.errors.password}>
          <Input type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="new-password" />
        </Field>
        <Field label={t('confirmPassword')} error={form.errors.password_confirmation}>
          <Input type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} autoComplete="new-password" />
        </Field>

        <div className="space-y-5 border-t pt-5 app-border">
          <div>
            <h2 className="text-sm font-bold app-text">Business setup</h2>
            <p className="mt-1 text-sm app-text-muted">
              Reservation and lead-based businesses are coming soon. For now, YouGo supports appointment-based setup.
            </p>
          </div>
          <Field label="Business name" error={form.errors.business_name}>
            <Input value={form.data.business_name} onChange={(event) => form.setData('business_name', event.target.value)} autoComplete="organization" />
          </Field>
          <Field label="Business type" error={form.errors.business_type}>
            <select
              value={form.data.business_type}
              onChange={(event) => form.setData('business_type', event.target.value)}
              className="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 app-panel app-text focus:ring-[var(--app-focus)]"
            >
              <option value="">Select business type</option>
              {businessTaxonomy.map((option) => (
                <option key={option.slug} value={option.slug}>{option.label}</option>
              ))}
            </select>
          </Field>
        </div>

        <Button className="w-full" disabled={form.processing}>
          <UserPlus className="h-4 w-4" />
          {t('createAccount')}
        </Button>
      </form>

      <p className="mt-6 text-center text-sm app-text-muted">
        {t('haveAccount')} <Link href="/login" className="font-bold text-indigo-600">{t('signIn')}</Link>
      </p>
    </AuthLayout>
  );
}
