import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { MessageSquarePlus, Mic, Send, Sparkles, User } from 'lucide-react';
import { toast } from 'sonner';
import { ChatShell } from '@/Components/ChatShell';
import { Salon } from '@/types';
import { useT } from '@/i18n';

type Message = {
  role: 'user' | 'assistant';
  content: string;
};

function assistantName(salon: Salon): string {
  return salon.ai_assistant_name?.trim() || 'Bella';
}

function assistantTypingLabel(salon: Salon, locale: string): string {
  return locale === 'en' ? `${assistantName(salon)} is typing...` : `${assistantName(salon)} scrie...`;
}

function buildGreeting(salon: Salon, locale: string): string {
  const isRo = locale !== 'en';
  const name = assistantName(salon);
  const configuredSummary = salon.ai_business_summary?.trim();

  if (configuredSummary) {
    return isRo
      ? `Buna! Sunt ${name}, asistentul virtual pentru ${salon.name}.\n\n${configuredSummary}`
      : `Hi! I'm ${name}, the virtual assistant for ${salon.name}.\n\n${configuredSummary}`;
  }

  return isRo
    ? `Buna! Sunt ${name}, asistentul virtual pentru ${salon.name}. Te pot ajuta cu servicii, locatii si programari.`
    : `Hi! I'm ${name}, the virtual assistant for ${salon.name}. I can help with services, locations, and bookings.`;
}

function csrfTokens() {
  const metaToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
  const cookieToken = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')[1];

  return {
    csrf: metaToken,
    xsrf: cookieToken ? decodeURIComponent(cookieToken) : '',
  };
}

function sessionKey(storageKey: string) {
  return `yougo-assistant:${storageKey}:conversation-id`;
}

function messagesSessionKey(storageKey: string) {
  return `yougo-assistant:${storageKey}:messages`;
}

function storedMessages(storageKey: string): Message[] | null {
  if (typeof window === 'undefined') return null;

  try {
    const raw = window.sessionStorage.getItem(messagesSessionKey(storageKey));
    if (!raw) return null;

    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return null;

    const messages = parsed.filter((message): message is Message => (
      message
      && (message.role === 'assistant' || message.role === 'user')
      && typeof message.content === 'string'
      && message.content.trim().length > 0
    ));

    return messages.length ? messages : null;
  } catch {
    window.sessionStorage.removeItem(messagesSessionKey(storageKey));
    return null;
  }
}

export function AssistantWidget({
  salon,
  locale = 'ro',
  chatEndpoint,
  storageKey,
  compact = false,
  primaryColor,
}: {
  salon: Salon;
  locale?: string;
  chatEndpoint?: string;
  storageKey?: string;
  compact?: boolean;
  primaryColor?: string | null;
}) {
  const t = useT();
  const name = assistantName(salon);
  const conversationStorageKey = storageKey ?? String(salon.id);
  const endpoint = chatEndpoint ?? `/assistant/${salon.id}/chat`;
  const fallbackMessage = salon.ai_handoff_message?.trim() || t('assistantFallback');
  const initialGreeting = useMemo(() => buildGreeting(salon, locale), [salon, locale]);
  const [messages, setMessages] = useState<Message[]>(() => storedMessages(conversationStorageKey) ?? [{ role: 'assistant', content: initialGreeting }]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [listening, setListening] = useState(false);
  const [conversationId, setConversationId] = useState<number | null>(() => {
    if (typeof window === 'undefined') return null;
    const stored = window.sessionStorage.getItem(sessionKey(conversationStorageKey));
    return stored ? Number(stored) || null : null;
  });
  const scrollRef = useRef<HTMLDivElement>(null);
  const conversationIdRef = useRef<number | null>(conversationId);

  useEffect(() => { conversationIdRef.current = conversationId; }, [conversationId]);

  useEffect(() => {
    if (!conversationId) return;
    window.sessionStorage.setItem(sessionKey(conversationStorageKey), String(conversationId));
  }, [conversationId, conversationStorageKey]);

  useEffect(() => {
    window.sessionStorage.setItem(messagesSessionKey(conversationStorageKey), JSON.stringify(messages.slice(-30)));
  }, [messages, conversationStorageKey]);

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, loading]);

  async function send(text: string) {
    if (!text.trim() || loading) return;

    const nextMessages = [...messages, { role: 'user' as const, content: text.trim() }];
    setMessages(nextMessages);
    setInput('');
    setLoading(true);

    try {
      const tokens = csrfTokens();
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(tokens.csrf ? { 'X-CSRF-TOKEN': tokens.csrf } : {}),
          ...(tokens.xsrf ? { 'X-XSRF-TOKEN': tokens.xsrf } : {}),
        },
        body: JSON.stringify({ conversation_id: conversationIdRef.current, messages: nextMessages }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || t('aiUnavailable'));
      }

      if (data.conversation_id) {
        setConversationId(data.conversation_id);
      }

      setMessages([...nextMessages, { role: 'assistant', content: data.message }]);
    } catch (error) {
      const message = error instanceof Error ? error.message : t('unknownError');
      toast.error(message);
      setMessages([...nextMessages, { role: 'assistant', content: fallbackMessage }]);
    } finally {
      setLoading(false);
    }
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    void send(input);
  }

  function startNewChat() {
    const greeting = { role: 'assistant' as const, content: initialGreeting };

    setMessages([greeting]);
    setInput('');
    setConversationId(null);
    conversationIdRef.current = null;
    window.sessionStorage.removeItem(sessionKey(conversationStorageKey));
    window.sessionStorage.setItem(messagesSessionKey(conversationStorageKey), JSON.stringify([greeting]));
  }

  function startVoice() {
    if (loading) return;

    const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition) {
      toast.error(t('browserNoSpeech'));
      return;
    }

    const recognition = new SpeechRecognition();
    recognition.lang = locale === 'en' ? 'en-GB' : 'ro-RO';
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
    <ChatShell
      title={name}
      statusLabel={loading ? assistantTypingLabel(salon, locale) : 'Online'}
      bodyRef={scrollRef}
      heightClassName={compact ? 'h-screen min-h-screen rounded-none' : 'h-[min(680px,calc(100vh-8rem))] min-h-[520px]'}
      className="border-[var(--app-border)] bg-[var(--app-shell)]"
      headerClassName="border-[var(--app-border)] bg-gradient-to-r from-blue-500/15 to-blue-900/15 dark:from-blue-500/25 dark:to-blue-900/25"
      bodyClassName="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4"
      footerClassName="border-t border-[var(--app-border)] p-4"
      action={
        <div className="flex items-center gap-2">
          <button
            type="button"
            aria-label={t('newChat')}
            title={t('newChat')}
            onClick={startNewChat}
            disabled={loading}
            className="flex h-10 w-10 items-center justify-center rounded-lg border transition app-panel app-text-soft hover:bg-[var(--app-panel-soft)] disabled:cursor-not-allowed disabled:opacity-50"
          >
            <MessageSquarePlus className="h-5 w-5" />
          </button>
          <button
            type="button"
            aria-label={t('voiceAgent')}
            onClick={startVoice}
            disabled={loading}
            className={`flex h-10 w-10 items-center justify-center rounded-lg border transition app-panel app-text-soft hover:bg-[var(--app-panel-soft)] disabled:cursor-not-allowed disabled:opacity-50 ${listening ? 'border-red-500 bg-red-600 text-white hover:bg-red-700' : ''}`}
          >
            <Mic className="h-5 w-5" />
          </button>
        </div>
      }
      footer={
        <form onSubmit={submit}>
          <div className="flex items-center gap-2 rounded-xl border px-3 py-2 app-panel">
            <input
              value={input}
              onChange={(event) => setInput(event.target.value)}
              placeholder={loading ? assistantTypingLabel(salon, locale) : t('typeMessage')}
              disabled={loading}
              className="min-w-0 flex-1 bg-transparent text-sm font-medium app-text placeholder:text-[var(--app-text-muted)] focus:outline-none disabled:cursor-not-allowed"
            />
            <button
              type="submit"
              className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white transition disabled:cursor-not-allowed disabled:opacity-50"
              style={{ backgroundColor: primaryColor || '#2563eb' }}
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
          <div className={`max-w-[86%] rounded-xl px-3 py-2 text-sm font-medium shadow-sm sm:max-w-[82%] ${message.role === 'assistant' ? 'rounded-tl-none app-panel-soft app-text' : 'rounded-tr-none chat-bubble-user'}`}>
            <p className="whitespace-pre-wrap leading-6"><InlineMarkdown text={message.content} /></p>
            <div className="mt-2 flex items-center gap-2 text-[10px] font-bold uppercase tracking-wide opacity-60">
              {message.role === 'assistant' ? <Sparkles className="h-3 w-3" /> : <User className="h-3 w-3" />}
              {message.role === 'assistant' ? name : t('clientName')}
            </div>
          </div>
        </div>
      ))}
      {loading && (
        <div className="flex justify-start">
          <div className="max-w-[82%] rounded-xl rounded-tl-none px-3 py-2 text-sm font-medium app-panel-soft app-text-soft">
            {assistantTypingLabel(salon, locale)}
          </div>
        </div>
      )}
    </ChatShell>
  );
}

function InlineMarkdown({ text }: { text: string }) {
  return <>{text.replaceAll('**', '')}</>;
}
