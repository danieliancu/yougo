@php
    $salon = $booking->salon;
    $service = $booking->service;
    $location = $booking->location;
    $staff = $booking->staffMember;
    $statusLabel = match ($newStatus) {
        'confirmed' => 'Confirmat',
        'cancelled' => 'Anulat',
        'completed' => 'Finalizat',
        'pending' => 'In asteptare',
        default => ucfirst($newStatus),
    };
    $oldStatusLabel = match ($oldStatus) {
        'confirmed' => 'Confirmat',
        'cancelled' => 'Anulat',
        'completed' => 'Finalizat',
        'pending' => 'In asteptare',
        default => ucfirst($oldStatus),
    };
@endphp

<p>Statusul unei programari a fost actualizat.</p>

<p><strong>Business:</strong> {{ $salon?->name }}</p>
<p><strong>Client:</strong> {{ $booking->client_name }}</p>
<p><strong>Telefon:</strong> {{ $booking->client_phone ?: '-' }}</p>
<p><strong>Serviciu:</strong> {{ $service?->name ?: '-' }}</p>
<p><strong>Locatie:</strong> {{ $location?->name ?: '-' }}</p>
<p><strong>Membru echipa:</strong> {{ $staff?->name ?: collect($booking->staff ?? [])->filter()->implode(', ') ?: '-' }}</p>
<p><strong>Data:</strong> {{ \App\Support\BusinessLocalization::formatDate($booking->date, $salon) }}</p>
<p><strong>Ora:</strong> {{ $booking->time }}</p>
<p><strong>Status anterior:</strong> {{ $oldStatusLabel }}</p>
<p><strong>Status nou:</strong> {{ $statusLabel }}</p>

<p>
    <a href="{{ url('/dashboard/bookings') }}">Deschide programarile in dashboard</a>
</p>
