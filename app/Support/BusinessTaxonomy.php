<?php

namespace App\Support;

use App\Models\Salon;

class BusinessTaxonomy
{
    public static function all(): array
    {
        return [
            [
                'label' => 'Salon / Beauty',
                'slug' => 'salon-beauty',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => null,
                'description' => 'AI receptionist for salons and beauty businesses that helps clients book appointments, ask about services, prices, staff, opening hours and locations.',
                'page_focus' => 'YouGo helps beauty businesses answer client questions, explain services and collect appointment requests while the team is busy.',
                'common_questions' => ['Do you have availability today?', 'How much is this treatment?', 'Can I book with a specific stylist or technician?', 'Where are you located?'],
                'current_flow' => 'service -> location -> staff preference -> date -> time -> client details -> appointment request',
                'future_flow' => null,
                'safety_copy' => null,
                'industries' => [
                    self::industry('Hair salon', 'hair-salon', 'YouGo helps hair salons answer client questions, explain services, show prices, manage appointment requests and reduce missed calls while stylists are busy with clients.', ['Do you have availability today?', 'How much is a haircut or colouring?', 'Can I book with a specific stylist?', 'Where are you located?'], 'service -> date -> time -> preferred stylist -> client details -> appointment request'),
                    self::industry('Nail salon', 'nail-salon', 'YouGo helps nail salons handle manicure, pedicure, gel, extensions and maintenance appointment requests through chat, WhatsApp or phone in the future.', ['Do you do gel nails?', 'How much is infill?', 'Can I book for Friday?', 'Which technician is available?'], 'service -> preferred technician -> date -> time -> client details'),
                    self::industry('Beauty salon', 'beauty-salon', 'YouGo helps beauty salons answer repetitive questions about treatments, prices, availability and staff, while collecting complete appointment details automatically.', ['What treatments do you offer?', 'How long does the treatment take?', 'What is the price?', 'Can I book for next week?'], 'treatment -> location -> date -> time -> client details'),
                    self::industry('Barber shop', 'barber-shop', 'YouGo helps barber shops reduce missed calls, answer service and price questions, and collect booking requests for haircuts, beard trims and grooming services.', ['Are walk-ins available?', 'How much is a haircut and beard trim?', 'Can I book with a specific barber?'], 'service -> barber preference -> date -> time -> client details'),
                    self::industry('Aesthetic studio', 'aesthetic-studio', 'YouGo helps aesthetic studios manage treatment enquiries and appointment requests while making sure the AI stays within the configured business rules and does not invent medical claims.', ['What treatments do you provide?', 'How long does it take?', 'Do I need a consultation?', 'Can I book an appointment?'], 'treatment enquiry -> consultation need -> date -> time -> client details'),
                ],
            ],
            [
                'label' => 'Clinic / Healthcare',
                'slug' => 'clinic-healthcare',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => null,
                'description' => 'AI receptionist for clinics and healthcare providers that helps patients request appointments, ask about services, opening hours, locations and general non-emergency information.',
                'page_focus' => 'YouGo helps clinics handle appointment requests, opening hours, location queries and general non-emergency service information.',
                'common_questions' => ['Can I book an appointment?', 'What services do you offer?', 'What time are you open?', 'Where is the clinic?'],
                'current_flow' => 'appointment reason -> service -> preferred date/time -> patient details -> appointment request',
                'future_flow' => null,
                'safety_copy' => 'The AI should not diagnose, prescribe or replace emergency medical advice.',
                'industries' => [
                    self::industry('Medical clinic', 'medical-clinic', 'YouGo helps medical clinics handle appointment requests, opening hour questions, location queries and basic service information without replacing medical advice.', ['Can I book an appointment?', 'What services do you offer?', 'What time are you open?', 'Where is the clinic?'], 'appointment reason -> preferred date -> preferred time -> patient details -> appointment request', 'AI should not diagnose, prescribe or provide emergency advice. It should direct urgent cases to emergency services.'),
                    self::industry('Dental clinic', 'dental-clinic', 'YouGo helps dental clinics manage appointment requests for check-ups, hygiene, emergency dental enquiries and treatment consultations.', ['Do you have a dental appointment available?', 'How much is a check-up?', 'Can I book a hygiene appointment?', 'Do you handle emergency dental pain?'], 'appointment type -> urgency -> date -> time -> patient details'),
                    self::industry('Physiotherapy', 'physiotherapy', 'YouGo helps physiotherapy clinics collect appointment requests, understand the type of issue, and direct clients to the right service or practitioner.', ['Can I book physiotherapy?', 'Do you treat back pain?', 'How long is a session?', 'What is the price?'], 'issue type -> service -> practitioner preference -> date -> time -> client details'),
                    self::industry('Psychology', 'psychology', 'YouGo helps psychology and therapy practices handle initial appointment enquiries, availability questions and general service information in a calm, professional tone.', ['Do you offer therapy sessions?', 'Can I book an initial consultation?', 'Are sessions online or in person?', 'What are your prices?'], 'service type -> online/in-person -> date -> time -> client details', 'AI should not provide crisis counselling and should direct urgent mental health emergencies to appropriate emergency support.'),
                    self::industry('Aesthetic clinic', 'aesthetic-clinic', 'YouGo helps aesthetic clinics collect consultation requests and answer configured questions about treatments, prices and availability without inventing medical claims.', ['Do I need a consultation?', 'What treatments are available?', 'How much does it cost?', 'Can I book next week?'], 'treatment interest -> consultation -> date -> time -> client details'),
                ],
            ],
            [
                'label' => 'Auto service',
                'slug' => 'auto-service',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => null,
                'description' => 'AI receptionist for garages and auto service businesses that helps customers describe vehicle issues, request inspections, ask about prices and book service appointments.',
                'page_focus' => 'YouGo helps garages and auto service businesses collect useful vehicle details, explain services and handle appointment requests.',
                'common_questions' => ['Can I book an MOT?', 'My car makes a noise, can you check it?', 'Do you fit tyres?', 'How much is diagnostics?'],
                'current_flow' => 'problem/service -> vehicle details -> preferred date/time -> client details -> appointment request',
                'future_flow' => null,
                'safety_copy' => null,
                'industries' => [
                    self::industry('Car repair', 'car-repair', 'YouGo helps car repair garages collect useful vehicle and issue details before a booking request reaches the team.', ['My car makes a noise, can you check it?', 'Can I book a repair?', 'How much is diagnostics?'], 'problem -> vehicle make/model/year -> preferred date -> client details -> appointment request'),
                    self::industry('MOT / inspection', 'mot-inspection', 'YouGo helps garages handle MOT or inspection appointment requests, opening hours and availability questions.', ['Can I book an MOT?', 'How much is an inspection?', 'Do you have availability this week?'], 'service type -> vehicle details -> date -> time -> client details'),
                    self::industry('Tyres', 'tyres', 'YouGo helps tyre shops answer questions about tyre replacement, fitting, availability and appointment requests.', ['Do you fit tyres?', 'Can I book tyre replacement?', 'What tyre size do you need?'], 'tyre issue/service -> vehicle/tyre size -> date -> time -> client details'),
                    self::industry('Detailing', 'detailing', 'YouGo helps car detailing businesses explain packages, prices, duration and collect booking requests.', ['What detailing packages do you offer?', 'How long does it take?', 'Can I book for Saturday?'], 'package -> vehicle type -> date -> time -> client details'),
                    self::industry('Car diagnostics', 'car-diagnostics', 'YouGo helps diagnostic garages collect symptoms, warning light details, vehicle information and preferred appointment times.', ['My engine light is on, can you check it?', 'How much is diagnostics?', 'When can I bring the car?'], 'symptom -> vehicle details -> urgency -> date -> time -> client details'),
                ],
            ],
            [
                'label' => 'Professional services',
                'slug' => 'professional-services',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => null,
                'description' => 'AI receptionist for professional service businesses that helps qualify enquiries, collect client details and arrange consultations.',
                'page_focus' => 'YouGo helps professional service businesses qualify enquiries, collect client details and arrange consultations.',
                'common_questions' => ['Can I book a consultation?', 'What services do you offer?', 'Can someone call me back?', 'What information do you need from me?'],
                'current_flow' => 'enquiry type -> consultation preference -> preferred date/time -> contact details',
                'future_flow' => null,
                'safety_copy' => 'For legal, financial or regulated services, the AI should collect details and arrange follow-up, not provide regulated advice.',
                'industries' => [
                    self::industry('Accounting', 'accounting', 'YouGo helps accountants collect enquiries about tax returns, bookkeeping, payroll and consultations, then pass clear details to the team.', ['Can I book a consultation?', 'Do you handle self-assessment?', 'What documents do I need?'], 'enquiry type -> business/personal -> preferred consultation time -> contact details'),
                    self::industry('Legal services', 'legal-services', 'YouGo helps legal service providers collect initial enquiry details and route appointment requests without giving legal advice.', ['Can I speak with a solicitor?', 'Do you handle family/property/business matters?', 'How do I book a consultation?'], 'matter type -> urgency -> preferred consultation time -> contact details', 'AI should not provide legal advice. It should collect details and offer an appointment or handover.'),
                    self::industry('Consulting', 'consulting', 'YouGo helps consultants qualify enquiries, understand business needs and collect contact details for a consultation.', ['Can I book a consultation?', 'Do you work with small businesses?', 'What services do you offer?'], 'business need -> service area -> preferred time -> contact details'),
                    self::industry('Insurance', 'insurance', 'YouGo helps insurance brokers collect enquiry details, understand what type of cover is needed and arrange follow-up.', ['Can I get a quote?', 'What insurance do I need?', 'Can someone call me back?'], 'insurance type -> basic details -> preferred contact time -> contact details'),
                    self::industry('Financial advice', 'financial-advice', 'YouGo helps financial advice firms collect consultation requests and basic enquiry details without providing regulated financial advice.', ['Can I book a consultation?', 'Do you help with mortgages/pensions/investments?', 'What are your fees?'], 'enquiry area -> consultation preference -> contact details', 'AI should not provide regulated financial advice. It should collect details and arrange a human follow-up.'),
                ],
            ],
            [
                'label' => 'Restaurant',
                'slug' => 'restaurant',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => Salon::MODE_RESERVATION,
                'description' => 'AI receptionist for restaurants, cafes and catering businesses. For now it can handle enquiries and appointment-style requests; full reservation mode will be added later.',
                'page_focus' => 'YouGo helps restaurants, cafes and catering businesses answer enquiries and collect booking-style requests.',
                'common_questions' => ['Are you open tonight?', 'Can I book a table?', 'Do you have vegetarian options?', 'Do you host private events?'],
                'current_flow' => 'enquiry -> date/time preference -> party size -> contact details',
                'future_flow' => 'date -> time -> party size -> table availability -> reservation',
                'safety_copy' => null,
                'industries' => [
                    self::industry('Restaurant', 'restaurant', 'YouGo helps restaurants answer questions about opening hours, menu, location and reservation enquiries. Full table reservation logic will be added later.', ['Are you open tonight?', 'Can I book a table?', 'Do you have vegetarian options?'], 'enquiry -> date/time preference -> party size -> contact details', null, 'date -> time -> party size -> table availability -> reservation'),
                    self::industry('Cafe', 'cafe', 'YouGo helps cafes answer questions about opening hours, menu, events and booking enquiries.', ['Are you open today?', 'Do you take bookings?', 'Do you serve breakfast?'], 'enquiry -> date/time preference -> contact details'),
                    self::industry('Bar', 'bar', 'YouGo helps bars answer event, opening hour, table enquiry and private booking questions.', ['Can I book a table?', 'Are you open late?', 'Do you host private events?'], 'enquiry -> party size/date/time -> contact details'),
                    self::industry('Catering', 'catering', 'YouGo helps catering businesses collect event enquiries, dates, guest numbers, menu preferences and contact details.', ['Do you cater weddings?', 'Can I get a quote?', 'Are you available on this date?'], 'event type -> date -> guest count -> menu needs -> contact details'),
                ],
            ],
            [
                'label' => 'Hotel / Accommodation',
                'slug' => 'hotel-accommodation',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => Salon::MODE_RESERVATION,
                'description' => 'AI receptionist for accommodation businesses. For now it handles booking enquiries and guest questions; full room availability and reservation mode will be added later.',
                'page_focus' => 'YouGo helps accommodation businesses answer guest questions and collect booking enquiries.',
                'common_questions' => ['Do you have rooms available?', 'What is the price per night?', 'Is breakfast included?', 'What time is check-in?'],
                'current_flow' => 'check-in date -> nights -> guests -> room preference -> contact details',
                'future_flow' => 'dates -> guests -> room availability -> booking',
                'safety_copy' => null,
                'industries' => [
                    self::industry('Hotel', 'hotel', 'YouGo helps hotels answer guest questions and collect booking enquiries. Full room availability and reservation management will be added later.', ['Do you have rooms available?', 'What is the price per night?', 'Is breakfast included?'], 'check-in date -> nights -> guests -> room preference -> contact details', null, 'dates -> guests -> room availability -> booking'),
                    self::industry('Guest house', 'guest-house', 'YouGo helps guest houses answer availability, pricing, location and facility questions while collecting booking enquiries.', ['Do you have availability this weekend?', 'Is parking available?', 'Can I book directly?'], 'date -> nights -> guests -> contact details'),
                    self::industry('B&B', 'b-and-b', 'YouGo helps B&Bs handle guest enquiries about availability, breakfast, check-in, rooms and location.', ['Is breakfast included?', 'Do you have a double room?', 'What time is check-in?'], 'date -> nights -> guests -> room preference -> contact details'),
                    self::industry('Holiday rental', 'holiday-rental', 'YouGo helps holiday rental businesses collect stay enquiries, dates, number of guests and property preferences.', ['Is the property available in August?', 'How many guests can stay?', 'Is there parking?'], 'property/stay enquiry -> dates -> guests -> contact details'),
                ],
            ],
            [
                'label' => 'Rental',
                'slug' => 'rental',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => Salon::MODE_RESERVATION,
                'description' => 'AI receptionist for rental businesses. For now it can collect rental enquiries; full reservation mode with resources, date ranges and availability will be added later.',
                'page_focus' => 'YouGo helps rental businesses collect rental enquiries, dates, item/resource needs and customer details.',
                'common_questions' => ['Do you have this available next week?', 'How much is it per day?', 'Can I rent it for two days?', 'Is delivery available?'],
                'current_flow' => 'rental item/resource -> start date -> end date/duration -> contact details',
                'future_flow' => 'resource -> start date -> end date -> availability -> reservation',
                'safety_copy' => null,
                'industries' => [
                    self::industry('Car rental', 'car-rental', 'YouGo helps car rental businesses collect vehicle rental enquiries, dates, vehicle type, driver details and contact information. Full reservation logic will be added later.', ['Do you have a car available next week?', 'How much is a van for two days?', 'Can I rent an automatic car?'], 'vehicle type -> start date -> end date -> contact details', null, 'vehicle/resource -> start date -> end date -> availability -> reservation'),
                    self::industry('Equipment rental', 'equipment-rental', 'YouGo helps equipment rental businesses collect enquiries about machines, tools or equipment, required dates and customer details.', ['Do you rent this equipment?', 'Is it available next weekend?', 'How much per day?'], 'equipment type -> rental period -> contact details'),
                    self::industry('Tool rental', 'tool-rental', 'YouGo helps tool rental businesses answer availability and price enquiries and collect hire requests.', ['Do you hire drills or saws?', 'Can I rent it for one day?', 'What deposit is required?'], 'tool type -> rental date/duration -> contact details'),
                    self::industry('Event rental', 'event-rental', 'YouGo helps event rental businesses collect enquiries for furniture, decorations, equipment, event dates and delivery details.', ['Do you rent chairs and tables?', 'Are you available for my event date?', 'Can you deliver?'], 'event type -> rental items -> event date -> contact details'),
                    self::industry('Property rental', 'property-rental', 'YouGo helps property rental businesses collect tenant or short-stay enquiries, property preferences, dates and contact details.', ['Is this property available?', 'Can I arrange a viewing?', 'What is the rent?'], 'property interest -> date/viewing request -> contact details'),
                ],
            ],
            [
                'label' => 'Real estate',
                'slug' => 'real-estate',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => Salon::MODE_LEAD,
                'description' => 'AI receptionist for estate agents and property businesses. For now it can collect enquiries and viewing requests; full lead mode will be added later.',
                'page_focus' => 'YouGo helps estate agents and property businesses collect enquiries, viewing requests and contact details.',
                'common_questions' => ['Is this property still available?', 'Can I book a viewing?', 'What is the asking price?', 'Can someone contact me?'],
                'current_flow' => 'property/enquiry type -> viewing request/contact preference -> contact details',
                'future_flow' => 'buy/sell/rent intent -> budget/property/location -> contact details -> lead',
                'safety_copy' => null,
                'industries' => [
                    self::industry('Property sales', 'property-sales', 'YouGo helps estate agents collect buyer enquiries, property interests, viewing requests and contact details.', ['Is this property still available?', 'Can I book a viewing?', 'What is the asking price?'], 'property interest -> viewing date/time -> contact details', null, 'buy/sell intent -> budget/property -> location -> contact details -> lead'),
                    self::industry('Property lettings', 'property-lettings', 'YouGo helps letting agents collect tenant enquiries, viewing requests and property requirements.', ['Is this flat available?', 'Can I book a viewing?', 'What documents do I need?'], 'property interest -> viewing request -> contact details'),
                    self::industry('Property management', 'property-management', 'YouGo helps property management companies handle tenant, landlord and maintenance enquiries and route them to the right team.', ['I need to report a repair.', 'Can someone contact me?', 'Do you manage properties in this area?'], 'enquiry type -> property/address -> urgency -> contact details'),
                ],
            ],
            [
                'label' => 'Other',
                'slug' => 'other',
                'default_mode' => Salon::MODE_APPOINTMENT,
                'future_mode' => null,
                'description' => 'For businesses that do not fit a predefined category. YouGo can still collect enquiries and appointment requests using the configured services, locations and AI settings.',
                'page_focus' => 'YouGo can be configured for other appointment-style businesses by adding services, locations, staff and AI instructions.',
                'common_questions' => ['What services do you offer?', 'Can I book a time?', 'Where are you located?'],
                'current_flow' => 'enquiry -> service -> date/time -> contact details',
                'future_flow' => null,
                'safety_copy' => null,
                'industries' => [
                    self::industry('Other', 'other', 'YouGo can be configured for other appointment-style businesses by adding services, locations, staff and AI instructions in the dashboard.', ['What services do you offer?', 'Can I book a time?', 'Where are you located?'], 'enquiry -> service -> date/time -> contact details'),
                ],
            ],
        ];
    }

    public static function businessTypeSlugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    public static function findBusinessType(string $businessTypeSlug): ?array
    {
        foreach (self::all() as $businessType) {
            if ($businessType['slug'] === $businessTypeSlug) {
                return $businessType;
            }
        }

        return null;
    }

    public static function findIndustry(string $businessTypeSlug, string $industrySlug): ?array
    {
        $businessType = self::findBusinessType($businessTypeSlug);

        if (! $businessType) {
            return null;
        }

        foreach ($businessType['industries'] as $industry) {
            if ($industry['slug'] === $industrySlug) {
                return $industry;
            }
        }

        return null;
    }

    public static function industryLabels(string $businessTypeSlug, array $industrySlugs): array
    {
        return collect($industrySlugs)
            ->map(fn ($slug) => self::findIndustry($businessTypeSlug, (string) $slug)['label'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    public static function isValidCombination(?string $businessTypeSlug, ?string $industrySlug): bool
    {
        if (! is_string($businessTypeSlug) || ! is_string($industrySlug)) {
            return false;
        }

        return self::findIndustry($businessTypeSlug, $industrySlug) !== null;
    }

    private static function industry(string $label, string $slug, string $description, array $questions, string $currentFlow, ?string $safetyNote = null, ?string $futureFlow = null): array
    {
        return [
            'label' => $label,
            'slug' => $slug,
            'description' => $description,
            'questions' => $questions,
            'current_flow' => $currentFlow,
            'future_flow' => $futureFlow,
            'safety_note' => $safetyNote,
        ];
    }
}
