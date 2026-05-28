import { Loader2, MessageCircle, Send, X } from 'lucide-react';
import { FormEvent, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { PublicLocale } from '@/Components/PublicChrome';
import { translate } from '@/i18n';

type ChatMessage = {
  role: 'user' | 'assistant';
  content: string;
};

export type YouGoCopilotContext = {
  surface: 'public' | 'dashboard';
  current_path?: string;
  current_section?: string;
  authenticated?: boolean;
  plan_key?: string | null;
  business_name?: string | null;
};

const maxStoredMessages = 15;
const openStorageKey = 'yougo_copilot_open';

export function YouGoCopilot({ locale, context }: { locale: PublicLocale; context: YouGoCopilotContext }) {
  const storageKey = `yougo_copilot_chat_messages:${locale}`;
  const [open, setOpen] = useState(() => {
    if (typeof window === 'undefined') return false;
    return window.sessionStorage.getItem(openStorageKey) === 'true';
  });
  const [messages, setMessages] = useState<ChatMessage[]>(() => storedMessages(storageKey, locale) ?? initialMessages(locale));
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const listRef = useRef<HTMLDivElement>(null);
  const shouldSmoothScroll = useRef(false);
  const inFlightRef = useRef(false);
  const t = (key: string) => translate(locale, key);
  const quickQuestions = useMemo(() => [
    t('publicChatQuickFree'),
    t('publicChatQuickInstall'),
    t('publicChatQuickPlan'),
    t('publicChatQuickWhatsapp'),
    t('publicChatQuickBookings'),
  ], [locale]);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    window.sessionStorage.setItem(storageKey, JSON.stringify(messages.slice(-maxStoredMessages)));
  }, [messages, storageKey]);

  useLayoutEffect(() => {
    const list = listRef.current;
    if (!list) return;

    list.scrollTo({
      top: list.scrollHeight,
      behavior: shouldSmoothScroll.current ? 'smooth' : 'auto',
    });
    shouldSmoothScroll.current = false;
  }, [messages, loading, open]);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    window.sessionStorage.setItem(openStorageKey, open ? 'true' : 'false');
  }, [open]);

  async function sendMessage(content: string) {
    const trimmed = content.trim();
    if (! trimmed || inFlightRef.current) return;

    inFlightRef.current = true;
    shouldSmoothScroll.current = true;
    const nextMessages = [...messages, { role: 'user' as const, content: trimmed }].slice(-maxStoredMessages);
    setMessages(nextMessages);
    setInput('');
    setError('');
    setLoading(true);

    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), 45000);

    try {
      const response = await fetch('/yougo-assistant/chat', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          ...csrfHeaders(),
        },
        credentials: 'same-origin',
        signal: controller.signal,
        body: JSON.stringify({
          locale,
          messages: nextMessages.slice(-12).map(({ role, content }) => ({ role, content })),
          context: {
            ...context,
            current_path: typeof window !== 'undefined' ? window.location.pathname : context.current_path,
          },
        }),
      });
      const data = await response.json();

      if (! response.ok || ! data.message) {
        throw new Error('YouGo copilot request failed.');
      }

      const assistantMessage: ChatMessage = {
        role: 'assistant',
        content: cleanAssistantText(data.message),
      };

      shouldSmoothScroll.current = true;
      setMessages((current) => [...current, assistantMessage].slice(-maxStoredMessages));
    } catch {
      setError(t('publicChatError'));
    } finally {
      window.clearTimeout(timeoutId);
      inFlightRef.current = false;
      setLoading(false);
    }
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    sendMessage(input);
  }

  function openChat() {
    shouldSmoothScroll.current = true;
    setOpen(true);
  }

  return (
    <div className="fixed bottom-4 right-4 z-[80] sm:bottom-5 sm:right-5">
      {open && (
        <section className="mb-3 flex h-[min(620px,calc(100vh-7rem))] w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border shadow-2xl app-border app-panel sm:w-[400px]" aria-label={t('publicChatTitle')}>
          <div className="flex items-center justify-between gap-3 border-b p-4 app-border">
            <div className="flex min-w-0 items-center gap-3">
              <span className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl app-panel-soft">
                <img src="/images/icon.png" alt="" className="h-8 w-8 object-contain" />
              </span>
              <div className="min-w-0">
                <h2 className="truncate text-sm font-bold app-text">{t('publicChatTitle')}</h2>
                <p className="truncate text-xs app-text-muted">{t('publicChatSubtitle')}</p>
              </div>
            </div>
            <button type="button" onClick={() => setOpen(false)} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg app-text-soft hover:bg-[var(--soft)]" aria-label={t('publicChatClose')}>
              <X className="h-5 w-5" aria-hidden />
            </button>
          </div>

          <div ref={listRef} className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
            {messages.map((message, index) => (
              <div key={`${message.role}-${index}`} className={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                <div className="max-w-[86%]">
                  <div className={`whitespace-pre-line rounded-2xl px-3 py-2 text-sm leading-6 ${message.role === 'user' ? 'bg-indigo-600 text-white' : 'app-panel-soft app-text'}`}>
                    {message.role === 'assistant' ? cleanAssistantText(message.content) : message.content}
                  </div>
                </div>
              </div>
            ))}
            {loading && (
              <div className="flex items-center gap-2 text-xs font-medium app-text-muted">
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                {t('publicChatTyping')}
              </div>
            )}
          </div>

          <div className="border-t p-4 app-border">
            <div className="mb-3 flex gap-2 overflow-x-auto pb-1">
              {quickQuestions.map((question) => (
                <button
                  key={question}
                  type="button"
                  onClick={() => sendMessage(question)}
                  disabled={loading}
                  className="shrink-0 rounded-full border px-3 py-1.5 text-xs font-semibold app-border app-text-soft hover:bg-[var(--soft)] disabled:opacity-60"
                >
                  {question}
                </button>
              ))}
            </div>
            {error && <p className="mb-2 text-xs font-semibold text-red-600">{error}</p>}
            <form onSubmit={submit} className="flex items-center gap-2 rounded-xl border px-2 py-2 app-border app-panel-soft">
              <input
                value={input}
                onChange={(event) => setInput(event.target.value)}
                placeholder={t('publicChatPlaceholder')}
                maxLength={1000}
                className="min-w-0 flex-1 bg-transparent px-2 text-sm app-text placeholder:text-slate-400 focus:outline-none dark:placeholder:text-slate-500"
              />
              <button type="submit" disabled={loading || !input.trim()} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-white disabled:opacity-50" aria-label={t('publicChatSend')}>
                <Send className="h-4 w-4" aria-hidden />
              </button>
            </form>
          </div>
        </section>
      )}

      <button
        type="button"
        onClick={openChat}
        className="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-xl shadow-indigo-600/30 transition hover:bg-indigo-700"
        aria-label={t('publicChatOpen')}
        aria-expanded={open}
      >
        <MessageCircle className="h-6 w-6" aria-hidden />
        <span className="absolute right-2 top-2 h-2.5 w-2.5 rounded-full border-2 border-indigo-600 bg-emerald-400" aria-hidden />
      </button>
    </div>
  );
}

function initialMessages(locale: PublicLocale): ChatMessage[] {
  return [{ role: 'assistant', content: translate(locale, 'publicChatInitialMessage') }];
}

function storedMessages(storageKey: string, locale: PublicLocale): ChatMessage[] | null {
  if (typeof window === 'undefined') return null;

  try {
    const raw = window.sessionStorage.getItem(storageKey);
    if (!raw) return null;

    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return null;

    const messages = parsed.filter((message): message is ChatMessage => (
      message
      && (message.role === 'assistant' || message.role === 'user')
      && typeof message.content === 'string'
      && message.content.trim().length > 0
    ));

    if (messages.length > 0) {
      return messages;
    }

    const legacyRaw = window.sessionStorage.getItem('yougo_copilot_chat_messages');
    if (!legacyRaw) return null;

    const legacyParsed = JSON.parse(legacyRaw);
    if (!Array.isArray(legacyParsed)) return null;

    const legacyMessages = legacyParsed.filter((message): message is ChatMessage => (
      message
      && (message.role === 'assistant' || message.role === 'user')
      && typeof message.content === 'string'
      && message.content.trim().length > 0
    ));
    const firstMessage = legacyMessages[0]?.content ?? '';
    const expectedInitialMessage = translate(locale, 'publicChatInitialMessage');

    return firstMessage === expectedInitialMessage && legacyMessages.length ? legacyMessages : null;
  } catch {
    window.sessionStorage.removeItem(storageKey);
    return null;
  }
}

function cleanAssistantText(text: string) {
  return text
    .replace(/\*\*(.*?)\*\*/g, '$1')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/^\s*[-*]\s+/gm, '')
    .replace(/([.!?])\s+(\d+\.\s+)/g, '$1\n$2')
    .trim();
}

function csrfHeaders() {
  if (typeof document === 'undefined') return {};

  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  const xsrfToken = document.cookie
    .split('; ')
    .find((row) => row.startsWith('XSRF-TOKEN='))
    ?.split('=')[1];

  return {
    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
    ...(xsrfToken ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrfToken) } : {}),
  };
}
