import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { MessageSquarePlus, Mic, Minus, Send, Sparkles, Square, User } from 'lucide-react';
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

type BrowserSpeechRecognition = {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  onresult: ((event: SpeechRecognitionResultEventLike) => void) | null;
  onend: (() => void) | null;
  onerror: ((event: SpeechRecognitionErrorEventLike) => void) | null;
  start: () => void;
  stop: () => void;
  abort: () => void;
};

type BrowserSpeechRecognitionConstructor = new () => BrowserSpeechRecognition;

type SpeechRecognitionResultEventLike = {
  resultIndex: number;
  results: {
    length: number;
    [index: number]: {
      isFinal: boolean;
      [index: number]: {
        transcript: string;
      };
    };
  };
};

type SpeechRecognitionErrorEventLike = {
  error?: string;
};

type WindowWithSpeechRecognition = Window & {
  SpeechRecognition?: BrowserSpeechRecognitionConstructor;
  webkitSpeechRecognition?: BrowserSpeechRecognitionConstructor;
};

type StopVoiceOptions = {
  sendTranscript?: boolean;
  showFailure?: boolean;
};

type TranscribeResponse = {
  text?: string;
  detail?: string;
  error?: string;
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

function speechRecognitionConstructor(): BrowserSpeechRecognitionConstructor | null {
  if (typeof window === 'undefined') return null;

  const speechWindow = window as WindowWithSpeechRecognition;

  return speechWindow.SpeechRecognition ?? speechWindow.webkitSpeechRecognition ?? null;
}

function voiceRecognitionLang(locale: string) {
  return locale === 'en' ? 'en-GB' : 'ro-RO';
}

function normalizedTranscript(text: string) {
  return text.replace(/\s+/g, ' ').trim();
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
  const transcribeEndpoint = `/assistant/${salon.id}/transcribe`;
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
  const recognitionRef = useRef<BrowserSpeechRecognition | null>(null);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const backupRecorderRef = useRef<MediaRecorder | null>(null);
  const backupStreamRef = useRef<MediaStream | null>(null);
  const backupChunksRef = useRef<BlobPart[]>([]);
  const voiceSessionActiveRef = useRef(false);
  const voiceStopRequestedRef = useRef(false);
  const shouldSendVoiceRef = useRef(false);
  const shouldShowVoiceFailureRef = useRef(false);
  const shouldTranscribeRecordingRef = useRef(false);
  const shouldFallbackToRecordingRef = useRef(false);
  const finalVoiceTranscriptRef = useRef('');
  const interimVoiceTranscriptRef = useRef('');
  const liveVoiceTranscriptRef = useRef('');
  const speechErrorRef = useRef<string | null>(null);

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
    stopVoiceInput({ sendTranscript: false, showFailure: false });
  }, []);

  function resetSpeechTranscriptRefs() {
    finalVoiceTranscriptRef.current = '';
    interimVoiceTranscriptRef.current = '';
    liveVoiceTranscriptRef.current = '';
    speechErrorRef.current = null;
  }

  function transcribeAudioBlob(blob: Blob) {
    const formData = new FormData();
    formData.append('audio', blob, 'recording.webm');

    setLoading(true);
    fetch(transcribeEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    })
      .then(async (res) => {
        const data = await res.json().catch((): TranscribeResponse => ({}));

        if (!res.ok) {
          throw new Error(data.detail || t('speechFailed'));
        }

        return data;
      })
      .then((data: TranscribeResponse) => {
        if (data.text) {
          void send(data.text, { voiceInput: true });
        } else {
          setLoading(false);
          toast.error(data.detail || t('speechFailed'));
        }
      })
      .catch((error) => {
        setLoading(false);
        toast.error(error instanceof Error ? error.message : t('speechFailed'));
      });
  }

  function startBackupRecording() {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') return;

    navigator.mediaDevices.getUserMedia({ audio: true }).then((stream) => {
      if (!voiceSessionActiveRef.current) {
        stream.getTracks().forEach((track) => track.stop());
        return;
      }

      const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm')
          ? 'audio/webm'
          : '';
      const recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);

      backupChunksRef.current = [];
      backupStreamRef.current = stream;
      backupRecorderRef.current = recorder;

      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) backupChunksRef.current.push(event.data);
      };

      recorder.onstop = () => {
        stream.getTracks().forEach((track) => track.stop());
        backupStreamRef.current = null;
      };

      recorder.start(250);
    }).catch(() => {
      backupChunksRef.current = [];
      backupRecorderRef.current = null;
      backupStreamRef.current = null;
    });
  }

  function stopBackupRecording(options: { transcribe?: boolean } = {}) {
    const recorder = backupRecorderRef.current;
    const chunks = backupChunksRef.current;
    const shouldTranscribe = options.transcribe === true;

    backupRecorderRef.current = null;

    if (recorder && recorder.state !== 'inactive') {
      recorder.onstop = () => {
        backupStreamRef.current?.getTracks().forEach((track) => track.stop());
        backupStreamRef.current = null;

        if (!shouldTranscribe) {
          backupChunksRef.current = [];
          return;
        }

        const recordedChunks = backupChunksRef.current;
        backupChunksRef.current = [];

        if (recordedChunks.length === 0) {
          toast.error(t('speechFailed'));
          return;
        }

        transcribeAudioBlob(new Blob(recordedChunks, { type: recorder.mimeType || 'audio/webm' }));
      };
      recorder.stop();
      return;
    }

    backupStreamRef.current?.getTracks().forEach((track) => track.stop());
    backupStreamRef.current = null;
    backupChunksRef.current = [];

    if (shouldTranscribe && chunks.length > 0) {
      transcribeAudioBlob(new Blob(chunks, { type: recorder?.mimeType || 'audio/webm' }));
    }
  }

  function stopVoiceInput(options: StopVoiceOptions = {}) {
    const { sendTranscript = false, showFailure = false } = options;

    voiceSessionActiveRef.current = false;
    voiceStopRequestedRef.current = true;
    shouldSendVoiceRef.current = sendTranscript;
    shouldShowVoiceFailureRef.current = showFailure;
    if (!sendTranscript) {
      shouldFallbackToRecordingRef.current = false;
      stopBackupRecording({ transcribe: false });
    }

    if (recognitionRef.current) {
      try {
        if (sendTranscript) {
          recognitionRef.current.stop();
        } else {
          recognitionRef.current.abort();
        }
      } catch {
        recognitionRef.current = null;
        setListening(false);
      }
    }

    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== 'inactive') {
      shouldTranscribeRecordingRef.current = sendTranscript;
      mediaRecorderRef.current.stop();
    } else if (!recognitionRef.current) {
      setListening(false);
    }

    mediaRecorderRef.current = null;
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
    if (listening) {
      stopVoiceInput({ sendTranscript: false, showFailure: false });
    }
    void send(input);
  }

  function startNewChat() {
    stopVoiceInput({ sendTranscript: false, showFailure: false });
    resetSpeechTranscriptRefs();

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
    stopVoiceInput({ sendTranscript: false, showFailure: false });
    resetSpeechTranscriptRefs();

    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ type: 'yougo-widget:minimize' }, '*');
    }
  }

  function startVoice() {
    if (loading) return;

    if (listening) {
      stopVoiceInput({ sendTranscript: true, showFailure: true });
      return;
    }

    const SpeechRecognition = speechRecognitionConstructor();
    if (SpeechRecognition) {
      startSpeechRecognition(SpeechRecognition);
      return;
    }

    startRecordingFallback();
  }

  function startSpeechRecognition(SpeechRecognition: BrowserSpeechRecognitionConstructor) {
    resetSpeechTranscriptRefs();
    voiceSessionActiveRef.current = true;
    voiceStopRequestedRef.current = false;
    startBackupRecording();
    startSpeechRecognitionInstance(SpeechRecognition);
  }

  function startSpeechRecognitionInstance(SpeechRecognition: BrowserSpeechRecognitionConstructor) {
    const recognition = new SpeechRecognition();
    recognition.lang = voiceRecognitionLang(locale);
    recognition.continuous = true;
    recognition.interimResults = true;
    recognitionRef.current = recognition;
    shouldSendVoiceRef.current = false;
    shouldShowVoiceFailureRef.current = false;
    setListening(true);

    recognition.onresult = (event) => {
      let finalTranscript = finalVoiceTranscriptRef.current;
      let interimTranscript = '';

      for (let index = event.resultIndex; index < event.results.length; index += 1) {
        const transcript = event.results[index][0]?.transcript ?? '';

        if (event.results[index].isFinal) {
          finalTranscript += ` ${transcript}`;
        } else {
          interimTranscript += ` ${transcript}`;
        }
      }

      finalVoiceTranscriptRef.current = normalizedTranscript(finalTranscript);
      interimVoiceTranscriptRef.current = normalizedTranscript(interimTranscript);

      const liveTranscript = normalizedTranscript(`${finalVoiceTranscriptRef.current} ${interimVoiceTranscriptRef.current}`);
      liveVoiceTranscriptRef.current = liveTranscript;
      setInput(liveTranscript);
    };

    recognition.onerror = (event) => {
      speechErrorRef.current = event.error ?? 'unknown';

      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        voiceSessionActiveRef.current = false;
        shouldShowVoiceFailureRef.current = false;
        shouldFallbackToRecordingRef.current = false;
        toast.error(t('speechNotAllowed'));
      } else if (event.error === 'network') {
        voiceSessionActiveRef.current = false;
        shouldSendVoiceRef.current = false;
        shouldShowVoiceFailureRef.current = true;
        shouldFallbackToRecordingRef.current = false;
      }
    };

    recognition.onend = () => {
      const shouldSend = shouldSendVoiceRef.current;
      const shouldShowFailure = shouldShowVoiceFailureRef.current;
      const shouldFallbackToRecording = shouldFallbackToRecordingRef.current;
      const shouldKeepListening = voiceSessionActiveRef.current && !voiceStopRequestedRef.current && !shouldFallbackToRecording;
      shouldSendVoiceRef.current = false;
      shouldShowVoiceFailureRef.current = false;
      shouldFallbackToRecordingRef.current = false;
      recognitionRef.current = null;
      setListening(shouldKeepListening);

      const recognitionTranscript = normalizedTranscript(
        `${finalVoiceTranscriptRef.current} ${interimVoiceTranscriptRef.current}`,
      );
      const finalTranscript = recognitionTranscript || liveVoiceTranscriptRef.current;
      const speechError = speechErrorRef.current;

      if (shouldFallbackToRecording) {
        resetSpeechTranscriptRefs();
        startRecordingFallback();
        return;
      }

      if (shouldKeepListening) {
        window.setTimeout(() => {
          if (!voiceSessionActiveRef.current || voiceStopRequestedRef.current || recognitionRef.current) return;
          startSpeechRecognitionInstance(SpeechRecognition);
        }, 100);
        return;
      }

      resetSpeechTranscriptRefs();

      if (!shouldSend) return;

      if (finalTranscript) {
        stopBackupRecording({ transcribe: false });
        setInput(finalTranscript);
        void send(finalTranscript, { voiceInput: true });
        return;
      }

      if (shouldSend) {
        stopBackupRecording({ transcribe: true });
        return;
      }

      if (shouldShowFailure && speechError === 'network') {
        toast.error(t('browserNoSpeech'));
      } else if (shouldShowFailure && !speechError) {
        toast.error(t('speechFailed'));
      } else if (shouldShowFailure) {
        toast.error(t('speechFailed'));
      }
    };

    try {
      recognition.start();
    } catch {
      recognitionRef.current = null;
      voiceSessionActiveRef.current = false;
      voiceStopRequestedRef.current = false;
      shouldSendVoiceRef.current = false;
      shouldShowVoiceFailureRef.current = false;
      shouldFallbackToRecordingRef.current = false;
      setListening(false);
      toast.error(t('speechFailed'));
    }
  }

  function startRecordingFallback() {
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      toast.error(t('browserNoSpeech'));
      return;
    }

    navigator.mediaDevices.getUserMedia({ audio: true }).then((stream) => {
      const chunks: BlobPart[] = [];
      const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm')
          ? 'audio/webm'
          : '';
      const recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
      mediaRecorderRef.current = recorder;
      shouldTranscribeRecordingRef.current = true;
      setListening(true);

      recorder.ondataavailable = (e) => {
        if (e.data.size > 0) chunks.push(e.data);
      };

      recorder.onstop = () => {
        const shouldTranscribe = shouldTranscribeRecordingRef.current;
        shouldTranscribeRecordingRef.current = false;
        stream.getTracks().forEach((t) => t.stop());
        mediaRecorderRef.current = null;
        setListening(false);

        if (!shouldTranscribe) {
          return;
        }

        if (chunks.length === 0) {
          toast.error(t('speechFailed'));
          return;
        }

        const mimeType = recorder.mimeType || 'audio/webm';
        const blob = new Blob(chunks, { type: mimeType });
        transcribeAudioBlob(blob);
      };

      recorder.start(250);
    }).catch((err: DOMException) => {
      shouldTranscribeRecordingRef.current = false;
      if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
        toast.error(t('speechNotAllowed'));
      } else {
        toast.error(t('speechFailed'));
      }
    });
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
            disabled={loading}
            className={`flex h-10 w-10 items-center justify-center rounded-lg border transition disabled:cursor-not-allowed disabled:opacity-50 ${listening ? 'border-red-600 bg-red-600 text-white hover:bg-red-700' : 'app-panel app-text-soft hover:bg-[var(--app-panel-soft)]'}`}
          >
            {listening ? <Square className="h-4 w-4 fill-current" /> : <Mic className="h-5 w-5" />}
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
