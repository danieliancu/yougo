import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ThemeToggle } from '@/Components/Ui';
import { AssistantWidget } from '@/Components/AssistantWidget';
import { Salon } from '@/types';

function assistantName(salon: Salon): string {
  return salon.ai_assistant_name?.trim() || 'Bella';
}

export default function AssistantShow({ salon }: { salon: Salon }) {
  const { locale = 'ro' } = usePage<{ locale?: string }>().props;
  const name = assistantName(salon);

  return (
    <main className="flex min-h-screen flex-col overflow-x-hidden app-bg">
      <Head title={`${name} - ${salon.name}`} />

      <header className="shrink-0 border-b px-4 py-3 app-border app-shell sm:px-6">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-3">
          <Link href="/" className="flex min-w-0 items-center gap-3">
            <ArrowLeft className="h-4 w-4 shrink-0 app-text-muted" />
            {salon.logo_path ? (
              <img src={`/storage/${salon.logo_path}`} className="h-9 w-9 shrink-0 rounded-lg object-cover" alt={salon.name} />
            ) : (
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-sm font-black text-white">
                {salon.name.slice(0, 1).toUpperCase()}
              </span>
            )}
            <span className="min-w-0">
              <span className="block truncate text-sm font-black app-text">{salon.name}</span>
              <span className="block truncate text-xs font-semibold app-text-muted">{name} Assistant live</span>
            </span>
          </Link>
          <ThemeToggle />
        </div>
      </header>

      <section className="min-h-0 flex-1 px-3 py-4 sm:px-6 sm:py-6">
        <div className="mx-auto flex h-full max-w-3xl items-center justify-center">
          <AssistantWidget salon={salon} locale={locale} />
        </div>
      </section>
    </main>
  );
}
