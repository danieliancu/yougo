@php
    $salon = $booking->salon;
    $service = $booking->service;
    $location = $booking->location;
    $staff = $booking->staffMember;
    $isEn = ($salon?->display_language ?? config('app.locale', 'ro')) === 'en';
@endphp

<p>{{ $isEn ? 'A customer cancelled a booking through WhatsApp.' : 'Un client a anulat o programare prin WhatsApp.' }}</p>

<p><strong>{{ $isEn ? 'Business' : 'Business' }}:</strong> {{ $salon?->name }}</p>
<p><strong>{{ $isEn ? 'Customer' : 'Client' }}:</strong> {{ $booking->client_name }}</p>
<p><strong>{{ $isEn ? 'Phone' : 'Telefon' }}:</strong> {{ $booking->client_phone ?: '-' }}</p>
<p><strong>{{ $isEn ? 'Service' : 'Serviciu' }}:</strong> {{ $service?->name ?: '-' }}</p>
<p><strong>{{ $isEn ? 'Location' : 'Locatie' }}:</strong> {{ $location?->name ?: '-' }}</p>
<p><strong>{{ $isEn ? 'Team member' : 'Membru echipa' }}:</strong> {{ $staff?->name ?: collect($booking->staff ?? [])->filter()->implode(', ') ?: '-' }}</p>
<p><strong>{{ $isEn ? 'Date' : 'Data' }}:</strong> {{ \App\Support\BusinessLocalization::formatDate($booking->date, $salon) }}</p>
<p><strong>{{ $isEn ? 'Time' : 'Ora' }}:</strong> {{ $booking->time }}</p>
<p><strong>{{ $isEn ? 'Original status' : 'Status initial' }}:</strong> pending</p>
<p><strong>{{ $isEn ? 'Cancellation text' : 'Text anulare' }}:</strong> {{ $cancellationText ?: '-' }}</p>
<p><strong>{{ $isEn ? 'Source' : 'Sursa' }}:</strong> {{ $source }}</p>
<p><strong>{{ $isEn ? 'Cancelled at' : 'Anulata la' }}:</strong> {{ now()->toDateTimeString() }}</p>

<p>
    <a href="{{ url('/dashboard/bookings') }}">{{ $isEn ? 'Open bookings in dashboard' : 'Deschide programarile in dashboard' }}</a>
</p>
