@php
    $salon = $booking->salon;
    $service = $booking->service;
    $location = $booking->location;
    $staff = $booking->staffMember;
@endphp

<p>Ai primit o cerere noua de programare creata de asistentul AI.</p>

<p><strong>Business:</strong> {{ $salon?->name }}</p>
<p><strong>Client:</strong> {{ $booking->client_name }}</p>
<p><strong>Telefon:</strong> {{ $booking->client_phone ?: '-' }}</p>
<p><strong>Serviciu:</strong> {{ $service?->name ?: '-' }}</p>
<p><strong>Locatie:</strong> {{ $location?->name ?: '-' }}</p>
<p><strong>Membru echipa:</strong> {{ $staff?->name ?: collect($booking->staff ?? [])->filter()->implode(', ') ?: '-' }}</p>
<p><strong>Data:</strong> {{ optional($booking->date)->format('Y-m-d') }}</p>
<p><strong>Ora:</strong> {{ $booking->time }}</p>
<p><strong>Status:</strong> pending</p>
<p><strong>Sursa:</strong> AI assistant</p>

@if($conversationSummary)
    <p><strong>Rezumat conversatie:</strong> {{ $conversationSummary }}</p>
@endif

<p>
    <a href="{{ url('/dashboard/bookings') }}">Deschide programarile in dashboard</a>
</p>
