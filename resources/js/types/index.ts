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
  max_concurrent_bookings?: number | null;
};

export type Staff = {
  id: number;
  salon_id: number;
  location_id?: number | null;
  name: string;
  role?: string | null;
  email?: string | null;
  phone?: string | null;
  active?: boolean;
  working_hours?: Record<string, string> | null;
  location?: Location | null;
  locations?: Location[];
  services?: Service[];
};

export type Service = {
  id: number;
  salon_id: number;
  name: string;
  type?: string | null;
  staff?: string[] | null;
  price: string | number;
  duration: number;
  max_concurrent_bookings?: number | null;
  location_ids?: number[] | null;
  notes?: string | null;
  staff_members?: Staff[];
};

export type Booking = {
  id: number;
  salon_id: number;
  location_id?: number | null;
  service_id?: number | null;
  staff_id?: number | null;
  client_name: string;
  client_phone?: string | null;
  staff?: string[] | null;
  date: string;
  time: string;
  status: 'pending' | 'confirmed' | 'cancelled' | 'completed';
  source?: string | null;
  notification_sent_at?: string | null;
  location?: Location | null;
  service?: Service | null;
  staff_member?: Staff | null;
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
  channel: 'chat' | 'voice' | 'web_widget' | string;
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
  plan?: string | null;
  plan_started_at?: string | null;
  trial_ends_at?: string | null;
  logo_path?: string | null;
  timezone?: string | null;
  industry?: string | null;
  mode?: 'appointment' | 'reservation' | 'lead' | string | null;
  business_type?: string | null;
  widget_key?: string | null;
  widget_enabled?: boolean;
  widget_allowed_domains?: string[] | null;
  widget_primary_color?: string | null;
  widget_position?: 'bottom-right' | 'bottom-left' | string | null;
  onboarding_completed?: boolean;
  onboarding_skipped?: boolean;
  onboarding_completed_at?: string | null;
  onboarding_skipped_at?: string | null;
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
  ai_industry_categories?: string[] | null;
  ai_main_focus?: string | null;
  ai_custom_context?: string[] | null;
  ai_booking_enabled?: boolean;
  ai_collect_phone?: boolean;
  ai_handoff_message?: string | null;
  ai_unknown_answer_policy?: 'say_unknown' | 'handoff' | string | null;
  locations: Location[];
  staff: Staff[];
  services: Service[];
  bookings: Booking[];
  conversations: Conversation[];
};

export type Plan = {
  key: string;
  name: string;
  monthly_conversations: number;
  monthly_ai_messages: number;
  monthly_bookings: number;
  widgets_enabled: boolean;
  price_label: string;
  description: string;
  recommended?: boolean;
};

export type UsageSummary = {
  plan: Plan;
  usage: {
    conversations: number;
    ai_messages: number;
    bookings: number;
  };
  limits: {
    conversations: number;
    ai_messages: number;
    bookings: number;
  };
};

export type OnboardingStep = {
  key: string;
  label_key: string;
  description_key: string;
  href: string;
  completed: boolean;
  required: boolean;
  optional: boolean;
  coming_soon: boolean;
};

export type OnboardingChecklist = {
  steps: OnboardingStep[];
  progress: number;
  completed_count: number;
  total_required: number;
  can_complete: boolean;
  next_step?: OnboardingStep | null;
  completed: boolean;
  skipped: boolean;
};

export type OverviewData = {
  metrics: {
    total_conversations: number;
    conversations_today: number;
    open_conversations: number;
    abandoned_conversations: number;
    total_bookings: number;
    pending_bookings: number;
    confirmed_bookings: number;
    completed_bookings: number;
    bookings_today: number;
    bookings_this_week: number;
    conversion_rate: number;
  };
  latest_conversations: Conversation[];
  latest_bookings: Booking[];
  usage: UsageSummary;
};

export type PageProps<T = Record<string, unknown>> = T & {
  auth: { user: User | null };
  locale: string;
  flash: { success?: string | null; error?: string | null };
  businessTaxonomy?: unknown[];
};
