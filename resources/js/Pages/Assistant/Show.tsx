import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, MessageCircle } from 'lucide-react';
import { ThemeToggle } from '@/Components/Ui';
import { AssistantWidget } from '@/Components/AssistantWidget';
import { Salon } from '@/types';
import { useT } from '@/i18n';
import { useState } from 'react';

function assistantName(salon: Salon): string {
  return salon.ai_assistant_name?.trim() || 'Bella';
}

export default function AssistantShow({ salon, backUrl = '/dashboard/widget' }: { salon: Salon; backUrl?: string }) {
  const { locale = 'ro' } = usePage<{ locale?: string }>().props;
  const t = useT();
  const name = assistantName(salon);
  const [open, setOpen] = useState(false);
  const primaryColor = isHexColor(salon.widget_primary_color ?? '') ? salon.widget_primary_color as string : '#2563eb';
  const position = salon.widget_position === 'bottom-left' ? 'bottom-left' : 'bottom-right';
  const ctaText = salon.widget_cta_text?.trim();

  return (
    <main className="flex min-h-screen flex-col overflow-x-hidden app-bg">
      <Head title={`${name} - ${salon.name}`} />

      <header className="shrink-0 border-b px-4 py-3 app-border app-shell sm:px-6">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-3">
          <Link href={backUrl} className="flex min-w-0 items-center gap-3">
            <ArrowLeft className="h-4 w-4 shrink-0 app-text-muted" />
            {salon.logo_path ? (
              <img src={`/storage/${salon.logo_path}`} className="h-9 w-9 shrink-0 rounded-lg object-cover" alt={salon.name} />
            ) : (
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-sm font-bold text-white">
                {salon.name.slice(0, 1).toUpperCase()}
              </span>
            )}
            <span className="min-w-0">
              <span className="block truncate text-sm font-bold app-text">{salon.name}</span>
              <span className="block truncate text-xs font-medium app-text-muted">{t('bellaOnline', { name })}</span>
            </span>
          </Link>
          <ThemeToggle />
        </div>
      </header>

      <section className="min-h-0 flex-1 px-3 py-4 sm:px-6 sm:py-6">
        <div className="mx-auto flex h-full max-w-5xl items-center justify-center rounded-xl border border-dashed p-6 text-center app-border app-panel-soft">
          <div>
            <p className="text-sm font-bold app-text">{t('widgetIconPreview')}</p>
            <p className="mt-2 max-w-md text-sm app-text-muted">{t('previewAssistantHelp')}</p>
          </div>
        </div>
      </section>

      <div className={`fixed bottom-4 z-[80] flex flex-col items-end sm:bottom-5 ${position === 'bottom-left' ? 'left-4 sm:left-5' : 'right-4 sm:right-5'}`}>
        {open && (
          <div className="mb-3 w-[calc(100vw-2rem)] sm:w-[400px]">
            <AssistantWidget
              salon={salon}
              locale={locale}
              primaryColor={primaryColor}
              storageKey={`preview:${salon.id}`}
              compact={false}
            />
          </div>
        )}
        <div className={`flex items-center gap-2 ${position === 'bottom-left' ? 'flex-row-reverse' : 'flex-row'}`}>
          {ctaText && (
            <span className="max-w-56 truncate rounded-full border border-slate-200 bg-white px-3.5 py-2.5 text-xs font-bold leading-tight text-slate-950 shadow-lg shadow-slate-900/10">
              {ctaText}
            </span>
          )}
          <button
            type="button"
            className="relative flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-xl transition hover:brightness-95"
            style={{ backgroundColor: primaryColor, boxShadow: '0 20px 40px rgba(79, 70, 229, 0.30)' }}
            aria-label={t('publicChatOpen')}
            aria-expanded={open}
            onClick={() => setOpen((current) => !current)}
          >
            <MessageCircle className="h-6 w-6" aria-hidden />
            <span className="absolute right-2 top-2 h-2.5 w-2.5 rounded-full border-2 bg-emerald-400" style={{ borderColor: primaryColor }} aria-hidden />
          </button>
        </div>
      </div>
    </main>
  );
}

function isHexColor(value: string) {
  return /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(value.trim());
}
