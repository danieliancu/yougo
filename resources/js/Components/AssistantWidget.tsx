import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { MessageSquarePlus, Mic, Minus, Send, Sparkles, User } from 'lucide-react';
import { toast } from 'sonner';
import { ChatShell } from '@/Components/ChatShell';
import { Salon } from '@/types';
import { useT } from '@/i18n';

type Message = {
  role: 'user' | 'assistant';
  content: string;
};

type KnownContact = {
  name: string;
  phone: string;
};

type SpeechRecognitionInstance = {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  onstart: (() => void) | null;
  onend: (() => void) | null;
  onerror: (() => void) | null;
  onresult: ((event: any) => void) | null;
  start: () => void;
  stop: () => void;
  abort: () => void;
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

function lastContactKey(storageKey: string) {
  return `yougo-assistant:${storageKey}:last-contact`;
}

function storedLastContact(storageKey: string): KnownContact | null {
  if (typeof window === 'undefined') return null;

  try {
    const raw = window.localStorage.getItem(lastContactKey(storageKey));
    if (!raw) return null;

    const parsed = JSON.parse(raw);
    const name = typeof parsed?.name === 'string' ? parsed.name.trim() : '';
    const phone = typeof parsed?.phone === 'string' ? parsed.phone.trim() : '';

    return name && phone ? { name, phone } : null;
  } catch {
    window.localStorage.removeItem(lastContactKey(storageKey));
    return null;
  }
}

function storeLastContact(storageKey: string, contact: KnownContact) {
  if (typeof window === 'undefined') return;

  window.localStorage.setItem(lastContactKey(storageKey), JSON.stringify({
    name: contact.name,
    phone: contact.phone,
    updated_at: new Date().toISOString(),
  }));
}

function reuseContactMessage(contact: KnownContact, locale: string): string {
  return locale === 'en'
    ? `Would you like to use the previously used contact details for this booking as well: ${contact.name}, ${contact.phone}?`
    : `Vrei să folosim și pentru această programare datele folosite anterior: ${contact.name}, ${contact.phone}?`;
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

function shouldHighlightNewChat(messages: Message[]): boolean {
  const lastAssistantMessage = [...messages].reverse().find((message) => message.role === 'assistant');
  const content = lastAssistantMessage?.content.toLowerCase() ?? '';

  return content.includes('+') && (
    content.includes('conversa')
    || content.includes('conversation')
    || content.includes('new booking')
    || content.includes('programare nou')
  );
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
  const highlightNewChat = shouldHighlightNewChat(messages);
  const scrollRef = useRef<HTMLDivElement>(null);
  const conversationIdRef = useRef<number | null>(conversationId);
  const recognitionRef = useRef<SpeechRecognitionInstance | null>(null);

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

  useEffect(() => () => {
    stopVoiceInput();
  }, []);

  function speechLang() {
    return locale === 'en' ? 'en-GB' : 'ro-RO';
  }

  function stopVoiceInput() {
    if (recognitionRef.current) {
      recognitionRef.current.onerror = null;
      recognitionRef.current.onend = null;
      recognitionRef.current.abort();
    }
    recognitionRef.current = null;
    setListening(false);
  }

  async function send(text: string, options: { voiceInput?: boolean } = {}) {
    if (!text.trim() || loading) return;

    const nextMessages = [...messages, { role: 'user' as const, content: text.trim() }];
    setMessages(nextMessages);
    setInput('');
    setLoading(true);

    try {
      const tokens = csrfTokens();
      const knownContact = storedLastContact(conversationStorageKey);
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(tokens.csrf ? { 'X-CSRF-TOKEN': tokens.csrf } : {}),
          ...(tokens.xsrf ? { 'X-XSRF-TOKEN': tokens.xsrf } : {}),
        },
        body: JSON.stringify({
          conversation_id: conversationIdRef.current,
          messages: nextMessages,
          ...(knownContact ? { known_contact: knownContact } : {}),
          ...(options.voiceInput ? { voice_input_used: true } : {}),
        }),
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || t('aiUnavailable'));
      }

      if (data.conversation_id) {
        setConversationId(data.conversation_id);
      }

      const bookingContact = data.booking?.client_name && data.booking?.client_phone
        ? { name: String(data.booking.client_name), phone: String(data.booking.client_phone) }
        : null;
      if (bookingContact) {
        storeLastContact(conversationStorageKey, bookingContact);
      }

      const assistantMessage = String(data.message ?? fallbackMessage);
      setMessages([...nextMessages, { role: 'assistant', content: assistantMessage }]);
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
    stopVoiceInput();

    const lastContact = storedLastContact(conversationStorageKey);
    const greeting = {
      role: 'assistant' as const,
      content: lastContact ? reuseContactMessage(lastContact, locale) : initialGreeting,
    };

    setMessages([greeting]);
    setInput('');
    setConversationId(null);
    conversationIdRef.current = null;
    window.sessionStorage.removeItem(sessionKey(conversationStorageKey));
    window.sessionStorage.setItem(messagesSessionKey(conversationStorageKey), JSON.stringify([greeting]));
  }

  function minimizeWidget() {
    stopVoiceInput();

    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ type: 'yougo-widget:minimize' }, '*');
    }
  }

  function startVoice() {
    if (loading || listening) return;

    stopVoiceInput();

    const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition) {
      toast.error(t('browserNoSpeech'));
      return;
    }

    const recognition = new SpeechRecognition() as SpeechRecognitionInstance;
    recognitionRef.current = recognition;
    recognition.lang = speechLang();
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.onstart = () => setListening(true);
    recognition.onend = () => {
      recognitionRef.current = null;
      setListening(false);
    };
    recognition.onerror = () => {
      recognitionRef.current = null;
      setListening(false);
      toast.error(t('speechFailed'));
    };
    recognition.onresult = (event: any) => {
      const transcript = event.results?.[0]?.[0]?.transcript;
      if (transcript) {
        void send(transcript, { voiceInput: true });
      } else {
        toast.error(t('speechFailed'));
      }
    };
    recognition.start();
  }

  return (
    <ChatShell
      title={name}
      statusLabel={loading ? assistantTypingLabel(salon, locale) : listening ? t('voiceListening') : 'Online'}
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
            className={`flex h-10 w-10 items-center justify-center rounded-lg border transition disabled:cursor-not-allowed disabled:opacity-50 ${
              highlightNewChat
                ? 'border-blue-600 bg-blue-600 text-white shadow-sm hover:bg-blue-700'
                : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'
            }`}
          >
            <MessageSquarePlus className="h-5 w-5" />
          </button>
          {compact && (
            <button
              type="button"
              aria-label="Minimize"
              title="Minimize"
              onClick={minimizeWidget}
              disabled={loading}
              className="flex h-10 w-10 items-center justify-center rounded-lg border transition app-panel app-text-soft hover:bg-[var(--app-panel-soft)] disabled:cursor-not-allowed disabled:opacity-50"
            >
              <Minus className="h-5 w-5" />
            </button>
          )}
          <button
            type="button"
            aria-label={t('voiceAgent')}
            onClick={startVoice}
            disabled={loading || listening}
            className={`flex h-10 w-10 items-center justify-center rounded-lg border transition app-panel app-text-soft hover:bg-[var(--app-panel-soft)] disabled:cursor-not-allowed disabled:opacity-50 ${listening ? 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700' : ''}`}
          >
            <Mic className="h-5 w-5" />
          </button>
        </div>
      }
      footer={
        <form onSubmit={submit}>
          {listening && (
            <p className="mb-2 text-xs font-semibold text-blue-600 dark:text-blue-300">
              {t('voiceListening')}
            </p>
          )}
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
