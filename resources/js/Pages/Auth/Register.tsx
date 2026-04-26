import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/Ui';
import { UserPlus } from 'lucide-react';
import { useT } from '@/i18n';

export default function Register() {
  const t = useT();
  const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  });

  return (
    <AuthLayout title={t('createAccount')}>
      <h1 className="text-2xl font-black app-text">{t('createAccount')}</h1>
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
