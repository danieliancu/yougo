import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button, Field, Input } from '@/Components/Ui';
import { LogIn } from 'lucide-react';
import { useT } from '@/i18n';

export default function Login() {
  const t = useT();
  const form = useForm({
    email: '',
    password: '',
    remember: false,
  });

  return (
    <AuthLayout title={t('login')}>
      <h1 className="text-2xl font-black app-text">{t('welcomeBack')}</h1>
      <p className="mt-2 text-sm app-text-muted">{t('loginSubtitle')}</p>

      <form className="mt-8 space-y-5" onSubmit={(event) => { event.preventDefault(); form.post('/login'); }}>
        <Field label="Email" error={form.errors.email}>
          <Input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} autoComplete="email" />
        </Field>
        <Field label={t('password')} error={form.errors.password}>
          <Input type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="current-password" />
        </Field>
        <label className="flex items-center gap-2 text-sm app-text-soft">
          <input type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} />
          {t('rememberMe')}
        </label>
        <Button className="w-full" disabled={form.processing}>
          <LogIn className="h-4 w-4" />
          {t('enterDashboard')}
        </Button>
      </form>

      <p className="mt-6 text-center text-sm app-text-muted">
        {t('noAccount')} <Link href="/register" className="font-bold text-indigo-600">{t('createOne')}</Link>
      </p>
    </AuthLayout>
  );
}
