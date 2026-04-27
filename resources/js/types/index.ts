export type User = {
  id: number;
  name: string;
  email: string;
};

export type Location = {
  id: number;
  salon_id: number;
  name: string;
  address: string;
  email?: string | null;
  phone?: string | null;
  hours?: Record<string, string> | null;
};

export type Service = {
  id: number;
  salon_id: number;
  name: string;
  type?: string | null;
  staff?: string[] | null;
  price: string | number;
  duration: number;
  location_ids?: number[] | null;
  notes?: string | null;
};

export type Booking = {
  id: number;
  salon_id: number;
  location_id?: number | null;
  service_id?: number | null;
  client_name: string;
  client_phone?: string | null;
  staff?: string[] | null;
  date: string;
  time: string;
  status: 'pending' | 'confirmed' | 'cancelled' | 'completed';
  location?: Location | null;
  service?: Service | null;
  created_at?: string;
};

export type ConversationMessage = {
  id: number;
  conversation_id: number;
  role: 'user' | 'assistant';
  content: string;
  created_at?: string;
};

export type Conversation = {
  id: number;
  salon_id: number;
  booking_id?: number | null;
  visitor_number?: number | null;
  channel: 'chat' | 'voice';
  contact_name?: string | null;
  contact_phone?: string | null;
  contact_email?: string | null;
  status: 'open' | 'completed' | 'archived';
  intent: 'inquiry' | 'booking' | string;
  duration_seconds?: number | null;
  summary?: string | null;
  last_message_at?: string | null;
  created_at?: string;
  messages: ConversationMessage[];
  booking?: Booking | null;
};

export type Salon = {
  id: number;
  user_id: number;
  name: string;
  logo_path?: string | null;
  timezone?: string | null;
  industry?: string | null;
  mode?: 'appointment' | 'reservation' | 'lead' | string | null;
  business_type?: string | null;
  onboarding_completed?: boolean;
  country?: string | null;
  website?: string | null;
  business_phone?: string | null;
  notification_email?: string | null;
  email_notifications?: boolean;
  missed_call_alerts?: boolean;
  booking_confirmations?: boolean;
  display_language?: string | null;
  date_format?: string | null;
  service_categories?: string[] | null;
  service_staff?: string[] | null;
  ai_assistant_name?: string | null;
  ai_tone?: 'polite' | 'friendly' | 'professional' | 'warm' | string | null;
  ai_response_style?: 'short' | 'balanced' | 'detailed' | string | null;
  ai_language_mode?: 'auto' | 'ro' | 'en' | string | null;
  ai_custom_instructions?: string | null;
  ai_business_summary?: string | null;
  ai_booking_enabled?: boolean;
  ai_collect_phone?: boolean;
  ai_handoff_message?: string | null;
  ai_unknown_answer_policy?: 'say_unknown' | 'handoff' | string | null;
  locations: Location[];
  services: Service[];
  bookings: Booking[];
  conversations: Conversation[];
};

export type PageProps<T = Record<string, unknown>> = T & {
  auth: { user: User | null };
  locale: string;
  flash: { success?: string | null; error?: string | null };
};
