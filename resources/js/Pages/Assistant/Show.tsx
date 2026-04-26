import { Head, Link, usePage } from '@inertiajs/react';
import { Calendar, ExternalLink, LayoutDashboard, MapPin, MessageCircle, MessageSquare, Mic, Scissors, Send, Sparkles, User, Phone } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ThemeToggle } from '@/Components/Ui';
import { ChatShell } from '@/Components/ChatShell';
import { Salon } from '@/types';
import { useT } from '@/i18n';

function buildGreeting(salon: Salon, locale: string): string {
  const isRo = locale !== 'en';

  const hello = isRo
    ? `Bună! Sunt Bella, asistentul virtual pentru ${salon.name}.`
    : `Hi! I'm Bella, the virtual assistant for ${salon.name}.`;

  const hasServices = salon.services.length > 0;
  const hasHours = salon.locations.some(
    (l) => l.hours && Object.values(l.hours).some((v) => v && !v.toLowerCase().includes('inchis') && !v.toLowerCase().includes('closed')),
  );
  const hasLocations = salon.locations.length > 0;
  const hasBusinessInfo = !!(salon.website || salon.business_phone || salon.notification_email);

  const offers: string[] = [];

  offers.push(isRo ? 'o programare' : 'a booking');

  if (hasServices && hasHours) {
    offers.push(isRo ? 'informații despre serviciile sau programul nostru' : 'information about our services or schedule');
  } else if (hasServices) {
    offers.push(isRo ? 'informații despre serviciile noastre' : 'information about our services');
  } else if (hasHours) {
    offers.push(isRo ? 'informații despre programul nostru' : 'our opening hours');
  }

  if (hasLocations) {
    offers.push(isRo ? 'detalii despre locațiile noastre' : 'details about our locations');
  }

  if (hasBusinessInfo) {
    offers.push(isRo ? 'orice altă informație despre salon' : 'any other information about the salon');
  }

  const last = offers.pop()!;
  const offerLine = isRo
    ? `Vă pot ajuta cu ${offers.join(', ')} sau cu ${last}.`
    : `I can help you with ${offers.join(', ')}, or ${last}.`;

  return `${hello}\n\n${offerLine}`;
}

type Message = {
  role: 'user' | 'assistant';
  content: string;
};

const assistantNav: { id: string; label: string; href: string; icon: React.ElementType; dividerAfter?: true }[] = [
  { id: 'overview', label: 'overview', href: '/dashboard', icon: LayoutDashboard, dividerAfter: true },
  { id: 'conversations', label: 'conversations', href: '/dashboard/conversations', icon: MessageSquare },
  { id: 'voice-calls', label: 'voiceCalls', href: '/dashboard/voice-calls', icon: Phone },
  { id: 'whatsapp', label: 'whatsapp', href: '/dashboard/whatsapp', icon: MessageCircle, dividerAfter: true },
  { id: 'locations', label: 'locations', href: '/dashboard/locations', icon: MapPin },
  { id: 'services', label: 'services', href: '/dashboard/services', icon: Scissors },
  { id: 'bookings', label: 'bookings', href: '/dashboard/bookings', icon: Calendar },
];

function csrfToken() {
  const metaToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

  if (metaToken) {
    return metaToken;
  }

  const cookieToken = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')[1];

  return cookieToken ? decodeURIComponent(cookieToken) : '';
}

export default function AssistantShow({ salon }: { salon: Salon }) {
  const t = useT();
  const { locale = 'ro' } = usePage<{ locale?: string }>().props;
  const [messages, setMessages] = useState<Message[]>([
    {
      role: 'assistant',
      content: buildGreeting(salon, locale),
    },
  ]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [listening, setListening] = useState(false);
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [hasBooking, setHasBooking] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);
  const conversationIdRef = useRef<number | null>(null);
  const hasBookingRef = useRef(false);

  useEffect(() => { conversationIdRef.current = conversationId; }, [conversationId]);
  useEffect(() => { hasBookingRef.current = hasBooking; }, [hasBooking]);

  useEffect(() => {
    function markAbandoned() {
      if (!conversationIdRef.current || hasBookingRef.current) return;
      const fd = new FormData();
      fd.append('conversation_id', String(conversationIdRef.current));
      fd.append('_token', csrfToken());
      navigator.sendBeacon(`/assistant/${salon.id}/abandon`, fd);
    }
    window.addEventListener('beforeunload', markAbandoned);
    return () => window.removeEventListener('beforeunload', markAbandoned);
  }, [salon.id]);

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages]);

  async function send(text: string) {
    if (!text.trim() || loading) return;

    const nextMessages = [...messages, { role: 'user' as const, content: text.trim() }];
    setMessages(nextMessages);
    setInput('');
    setLoading(true);

    try {
      const token = csrfToken();
      const response = await fetch(`/assistant/${salon.id}/chat`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': token,
          'X-XSRF-TOKEN': token,
        },
        body: JSON.stringify({ conversation_id: conversationId, messages: nextMessages }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || t('aiUnavailable'));
      }

      if (data.conversation_id) {
        setConversationId(data.conversation_id);
      }

      if (data.booking) {
        setHasBooking(true);
      }

      setMessages([...nextMessages, { role: 'assistant', content: data.message }]);
    } catch (error) {
      const message = error instanceof Error ? error.message : t('unknownError');
      toast.error(message);
      setMessages([...nextMessages, { role: 'assistant', content: t('assistantFallback') }]);
    } finally {
      setLoading(false);
    }
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    void send(input);
  }

  function startVoice() {
    const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition) {
      toast.error(t('browserNoSpeech'));
      return;
    }

    const recognition = new SpeechRecognition();
    recognition.lang = 'ro-RO';
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.onstart = () => setListening(true);
    recognition.onend = () => setListening(false);
    recognition.onerror = () => {
      setListening(false);
      toast.error(t('speechFailed'));
    };
    recognition.onresult = (event: any) => {
      const transcript = event.results?.[0]?.[0]?.transcript;
      if (transcript) void send(transcript);
    };
    recognition.start();
  }

  return (
    <div className="flex min-h-screen overflow-x-hidden app-bg">
      <Head title={`${salon.name} Assistant`} />

      <aside className="fixed inset-y-0 left-0 z-40 hidden h-screen w-72 shrink-0 flex-col overflow-hidden app-sidebar lg:flex">
        <div className="shrink-0 border-b border-white/10 p-6">
          <Link href="/" className="flex w-fit flex-col gap-3">
            <img src="/images/logo-dark.png" className="h-12 w-auto shrink-0" alt="YouGo" />
            <div className="flex items-center gap-2.5">
              {salon.logo_path ? (
                <img src={`/storage/${salon.logo_path}`} className="h-7 w-7 shrink-0 rounded-md object-cover" alt={salon.name} />
              ) : (
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-white/10 text-xs font-black text-white">
                  {salon.name.slice(0, 1).toUpperCase()}
                </span>
              )}
              <span className="truncate text-sm font-bold text-white">{salon.name}</span>
            </div>
          </Link>
        </div>
        <nav className="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
          {assistantNav.map((item) => {
            const Icon = item.icon;

            return (
              <div key={item.id}>
                <Link
                  href={item.href}
                  className="flex items-center gap-3 rounded-lg px-4 py-3 text-sm font-bold text-slate-400 transition hover:bg-white/10 hover:text-white"
                >
                  <Icon className="h-4 w-4" />
                  {t(item.label)}
                </Link>
                {item.dividerAfter && <div className="mx-4 my-2 h-px bg-white/10" />}
              </div>
            );
          })}
        </nav>
        <div className="shrink-0 border-t border-white/10 p-4">
          <Link href={`/assistant/${salon.id}`} className="mb-4 flex items-center justify-center gap-2 rounded-lg bg-white/10 px-4 py-3 text-sm font-bold text-white hover:bg-white/15">
            <ExternalLink className="h-4 w-4" />
            {t('previewPublicAi')}
          </Link>

          <div className="flex w-full items-center gap-3 rounded-lg px-4 py-3 text-left hover:bg-white/10">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 text-sm font-black text-white">
              {salon.name.slice(0, 1).toUpperCase()}
            </span>
            <span className="min-w-0">
              <span className="block truncate text-sm font-black text-white">{salon.name}</span>
              <span className="block truncate text-xs text-slate-400">{t('assistantLiveLabel')}</span>
            </span>
          </div>
        </div>
      </aside>

      <main className="flex min-w-0 flex-1 flex-col lg:ml-72 lg:h-screen">
        <header className="relative z-10 flex min-h-16 shrink-0 items-center justify-between gap-3 border-b px-4 py-3 app-border app-shell sm:px-5 lg:px-8">
          <div className="min-w-0">
            <h1 className="truncate text-lg font-black app-text">{salon.name}</h1>
            <p className="truncate text-xs font-semibold app-text-muted">{t('assistantLiveLabel')}</p>
          </div>
          <div className="flex items-center gap-3">
            <ThemeToggle />
          </div>
        </header>

        <div className="min-h-0 flex-1 overflow-hidden px-4 py-4 sm:px-5 lg:px-8">
          <div className="flex h-full min-h-0 items-center justify-center">
            <ChatShell
              title={t('carouselAssistantAi')}
              statusLabel="Online"
              bodyRef={scrollRef}
              heightClassName="h-[620px]"
              className="border-[var(--app-border)] bg-[var(--app-shell)]"
              headerClassName="border-[var(--app-border)] bg-gradient-to-r from-blue-500/15 to-blue-900/15 dark:from-blue-500/25 dark:to-blue-900/25"
              bodyClassName="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4"
              footerClassName="border-t border-[var(--app-border)] p-4"
              action={
                <button
                  type="button"
                  aria-label={t('voiceAgent')}
                  onClick={startVoice}
                  className={`flex h-10 w-10 items-center justify-center rounded-lg border transition app-panel app-text-soft hover:bg-[var(--app-panel-soft)] ${listening ? 'border-red-500 bg-red-600 text-white hover:bg-red-700' : ''}`}
                >
                  <Mic className="h-5 w-5" />
                </button>
              }
              footer={
                <form onSubmit={submit}>
                  <div className="flex items-center gap-2 rounded-xl border px-3 py-2 app-panel">
                    <input
                      value={input}
                      onChange={(event) => setInput(event.target.value)}
                      placeholder={t('typeMessage')}
                      className="min-w-0 flex-1 bg-transparent text-sm font-semibold app-text placeholder:text-[var(--app-text-muted)] focus:outline-none"
                    />
                    <button
                      type="submit"
                      className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                      disabled={!input.trim() || loading}
                    >
                      <Send className="h-4 w-4" />
                    </button>
                  </div>
                </form>
              }
            >
              {messages.map((message, index) => (
                <div key={index} className={`flex ${message.role === 'assistant' ? 'justify-start' : 'justify-end'}`}>
                  <div className={`max-w-[82%] rounded-xl px-3 py-2 text-sm font-semibold shadow-sm ${message.role === 'assistant' ? 'rounded-tl-none app-panel-soft app-text' : 'rounded-tr-none chat-bubble-user'}`}>
                    <p className="whitespace-pre-wrap leading-6"><InlineMarkdown text={message.content} /></p>
                    <div className="mt-2 flex items-center gap-2 text-[10px] font-black uppercase tracking-wide opacity-60">
                      {message.role === 'assistant' ? <Sparkles className="h-3 w-3" /> : <User className="h-3 w-3" />}
                      {message.role === 'assistant' ? t('bellaName') : t('clientName')}
                    </div>
                  </div>
                </div>
              ))}
              {loading && (
                <div className="flex justify-start">
                  <div className="max-w-[82%] rounded-xl rounded-tl-none px-3 py-2 text-sm font-semibold app-panel-soft app-text-soft">
                    {t('bellaTyping')}
                  </div>
                </div>
              )}
            </ChatShell>
          </div>
        </div>
      </main>
    </div>
  );
}

function InlineMarkdown({ text }: { text: string }) {
  return <>{text.replaceAll('**', '')}</>;
}
