@php
    $salon = $conversation->salon;
    $booking = $conversation->booking;
    $service = $booking?->service;
    $location = $booking?->location;
    $staff = $booking?->staffMember;
@endphp

<p>Ai primit o cerere de modificare pentru o programare existenta.</p>

<p><strong>Business:</strong> {{ $salon?->name }}</p>
<p><strong>Sursa:</strong> {{ ucfirst($changeRequest['source'] ?? 'WhatsApp') }}</p>
<p><strong>Client:</strong> {{ $conversation->contact_name ?: $booking?->client_name ?: '-' }}</p>
<p><strong>Telefon:</strong> {{ str_replace('whatsapp:', '', $conversation->contact_phone ?: $booking?->client_phone ?: '-') }}</p>

<p><strong>Cerere client:</strong> {{ $changeRequest['requested_text'] ?? '-' }}</p>
<p><strong>Tip cerere:</strong> {{ $changeRequest['type'] ?? 'unknown' }}</p>
<p><strong>Status:</strong> pending</p>

@if($booking)
    <hr>
    <p><strong>Programare existenta</strong></p>
    <p><strong>Serviciu:</strong> {{ $service?->name ?: '-' }}</p>
    <p><strong>Locatie:</strong> {{ $location?->name ?: '-' }}</p>
    <p><strong>Membru echipa:</strong> {{ $staff?->name ?: collect($booking->staff ?? [])->filter()->implode(', ') ?: '-' }}</p>
    <p><strong>Data:</strong> {{ \App\Support\BusinessLocalization::formatDate($booking->date, $salon) }}</p>
    <p><strong>Ora:</strong> {{ $booking->time }}</p>
    <p><strong>Status programare:</strong> {{ $booking->status }}</p>
@endif

<p>
    <a href="{{ url('/dashboard/conversations') }}">Deschide conversatia in dashboard</a>
</p>
