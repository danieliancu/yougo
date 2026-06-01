@php
    $salon = $conversation->salon;
    $booking = $conversation->booking;
    $service = $booking?->service;
    $location = $booking?->location;
    $staff = $booking?->staffMember;
    $locale = $salon?->ai_language_mode && in_array($salon->ai_language_mode, ['ro', 'en'], true)
        ? $salon->ai_language_mode
        : ($salon?->display_language === 'en' ? 'en' : 'ro');
    $isEn = $locale === 'en';
@endphp

<p>{{ $isEn ? 'You received a booking change request.' : 'Ai primit o cerere de modificare pentru o programare existenta.' }}</p>

<p><strong>Business:</strong> {{ $salon?->name }}</p>
<p><strong>{{ $isEn ? 'Source' : 'Sursa' }}:</strong> {{ ucfirst($changeRequest['source'] ?? 'WhatsApp') }}</p>
<p><strong>{{ $isEn ? 'Customer' : 'Client' }}:</strong> {{ $conversation->contact_name ?: $booking?->client_name ?: '-' }}</p>
<p><strong>{{ $isEn ? 'Phone' : 'Telefon' }}:</strong> {{ str_replace('whatsapp:', '', $conversation->contact_phone ?: $booking?->client_phone ?: '-') }}</p>

<p><strong>{{ $isEn ? 'Customer request' : 'Cerere client' }}:</strong> {{ $changeRequest['requested_text'] ?? '-' }}</p>
<p><strong>{{ $isEn ? 'Request type' : 'Tip cerere' }}:</strong> {{ $changeRequest['type'] ?? 'unknown' }}</p>
<p><strong>{{ $isEn ? 'Request status' : 'Status cerere' }}:</strong> {{ $changeRequest['status'] ?? 'pending' }}</p>
<p><strong>{{ $isEn ? 'Requested at' : 'Solicitata la' }}:</strong> {{ $changeRequest['requested_at'] ?? '-' }}</p>

@if($booking)
    <hr>
    <p><strong>{{ $isEn ? 'Existing booking' : 'Programare existenta' }}</strong></p>
    <p><strong>{{ $isEn ? 'Service' : 'Serviciu' }}:</strong> {{ $service?->name ?: '-' }}</p>
    <p><strong>{{ $isEn ? 'Location' : 'Locatie' }}:</strong> {{ $location?->name ?: '-' }}</p>
    <p><strong>{{ $isEn ? 'Team member' : 'Membru echipa' }}:</strong> {{ $staff?->name ?: collect($booking->staff ?? [])->filter()->implode(', ') ?: '-' }}</p>
    <p><strong>{{ $isEn ? 'Date' : 'Data' }}:</strong> {{ \App\Support\BusinessLocalization::formatDate($booking->date, $salon) }}</p>
    <p><strong>{{ $isEn ? 'Time' : 'Ora' }}:</strong> {{ $booking->time }}</p>
    <p><strong>{{ $isEn ? 'Booking status' : 'Status programare' }}:</strong> {{ $booking->status }}</p>
    <p><strong>{{ $isEn ? 'Previous booking status' : 'Status anterior programare' }}:</strong> {{ $changeRequest['previous_booking_status'] ?? '-' }}</p>
@endif

<p>
    <a href="{{ url('/dashboard/conversations') }}">{{ $isEn ? 'Open the conversation in dashboard' : 'Deschide conversatia in dashboard' }}</a>
</p>
