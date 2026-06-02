<p>Ai primit o cerere pentru configurarea WhatsApp AI.</p>

<p><strong>Salon ID:</strong> {{ $salon->id }}</p>
<p><strong>Business:</strong> {{ $salon->name }}</p>
<p><strong>User autentificat:</strong> {{ $user?->name ?: '-' }} / {{ $user?->email ?: '-' }}</p>
<p><strong>Status integrare:</strong> {{ $integration?->status ?: 'not_connected' }}</p>
<p><strong>Requested at:</strong> {{ $integration?->requested_at?->toIso8601String() ?: '-' }}</p>

<hr>

<p><strong>Business name:</strong> {{ $form['business_name'] ?: '-' }}</p>
<p><strong>Contact person:</strong> {{ $form['contact_person'] }}</p>
<p><strong>Contact email:</strong> {{ $form['contact_email'] }}</p>
<p><strong>Contact phone:</strong> {{ $form['contact_phone'] }}</p>
<p><strong>Requested WhatsApp number:</strong> {{ $form['requested_whatsapp_number'] }}</p>
<p><strong>WhatsApp display name:</strong> {{ $form['whatsapp_display_name'] ?: '-' }}</p>
<p><strong>Website or social link:</strong> {{ $form['website_or_social_link'] ?: '-' }}</p>
<p><strong>Has Meta business account:</strong> {{ $form['has_meta_business_account'] ?: '-' }}</p>
<p><strong>Number currently used on WhatsApp app:</strong> {{ $form['number_currently_used_on_whatsapp_app'] ?: '-' }}</p>
<p><strong>Can receive SMS or call:</strong> {{ $form['can_receive_sms_or_call'] ?: '-' }}</p>
<p><strong>Preferred meeting type:</strong> {{ $form['preferred_meeting_type'] }}</p>
<p><strong>Preferred availability:</strong> {{ $form['preferred_availability'] }}</p>
<p><strong>Notes:</strong> {{ $form['notes'] ?: '-' }}</p>
