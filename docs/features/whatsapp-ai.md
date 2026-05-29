# WhatsApp AI

## Provider

WhatsApp AI uses Twilio as the WhatsApp provider.

The MVP does not provision Meta or Twilio senders automatically. It stores the business request and lets the YouGo team configure the sender manually in Twilio.

## MVP activation flow

1. The business owner opens Dashboard -> WhatsApp Settings.
2. They enter their business WhatsApp number.
3. They click Request activation.
4. YouGo stores `whatsapp_integrations.requested_number`, sets `status = requested`, and records `requested_at`.
5. A YouGo admin configures the WhatsApp sender in Twilio.
6. The admin manually activates the integration.
7. Once active, the business owner can toggle WhatsApp AI on or off.

Manual activation:

```bash
php artisan yougo:whatsapp-activate {salon_id} {twilio_sender}
```

Equivalent database changes:

1. Set `whatsapp_integrations.twilio_sender = 'whatsapp:+40...'`.
2. Set `display_number` if desired.
3. Set `status = 'active'`.
4. Set `activated_at = now()`.

## Plan access

WhatsApp AI requires a plan that includes the `whatsapp_ai` service key, such as Chat + WhatsApp or YouGo plans.

Free and Website Chat users see an upgrade-required state.

## Webhook routing and AI replies

Twilio sends inbound WhatsApp messages to:

```text
POST /twilio/whatsapp/webhook
```

The webhook maps Twilio `To` to `whatsapp_integrations.twilio_sender`, then stores the inbound message on the matching salon.

Webhook payload fields used:

- `From`
- `To`
- `Body`
- `MessageSid`
- `ProfileName`
- `WaId`
- `NumMedia`

`MessageSid` is used for deduplication.

When the integration is active, the salon plan includes `whatsapp_ai`, and `ai_enabled = true`, YouGo replies automatically to customer-initiated text messages.

The WhatsApp reply flow:

1. Saves the inbound Twilio message.
2. Reuses the same business assistant flow as website chat.
3. Adds WhatsApp-specific prompt instructions for short, natural replies.
4. Uses configured business data: services, prices, locations, staff, opening hours and localization.
5. Allows the existing appointment tools to create booking requests.
6. Sends the AI reply back through Twilio.
7. Saves the outbound message in the conversation transcript.

If the assistant creates a booking request, the existing booking notification email behavior is used.

## Persistence

WhatsApp uses the existing conversation tables:

- `conversations.channel = whatsapp`
- `conversations.provider = twilio`
- `conversations.external_contact_id = From`
- `conversations.external_sender = To`
- `conversation_messages.provider_message_id = MessageSid`
- `conversation_messages.direction = inbound` or `outbound`
- outbound AI metadata includes `channel = whatsapp`, `sent_via = twilio`, and `ai_generated = true`

This keeps WhatsApp conversations compatible with Dashboard -> Conversations.

## Usage tracking

The foundation records:

- `whatsapp_conversation`
- `whatsapp_message_inbound`
- `whatsapp_message_outbound`
- `whatsapp_ai_reply`

Inbound and outbound WhatsApp messages count toward `monthly_whatsapp_messages`.

If the monthly WhatsApp message limit is reached, YouGo does not call the AI. It can send a short fallback asking the customer to contact the business directly.

## Supported message types

The MVP supports customer-initiated text messages only.

Media-only messages are stored, but the automatic reply tells the customer that YouGo currently processes WhatsApp text messages only.

Unsupported in this phase:

- message templates
- campaigns or broadcasts
- images, audio, video, documents or voice notes
- human handover
- status callbacks

## Post-booking behavior

WhatsApp continues in the same customer thread after a booking request is created.

The assistant must not ask WhatsApp customers to press the website widget new-conversation control, start a new conversation, or open a new chat.

If the customer asks to change, cancel, reschedule, add details, change service, change time, or change location after a booking exists, YouGo does not automatically edit or cancel the booking. The request is stored on `conversations.metadata.booking_change_requests` with:

- `type`
- `requested_text`
- `source = whatsapp`
- `status = pending`
- `requested_at`

The assistant should answer naturally and say the request has been passed to the team for confirmation when enough detail is available.

When a new pending change request is recorded, YouGo sends the business a booking change request email if booking notifications are enabled and `notification_email` is configured. The conversation metadata is marked with `notified_at` after the email is sent.

Dashboard -> Conversations shows a simple pending change request indicator. A fuller booking-change workflow can be added later.

Future Phone AI must use its own channel behavior policy and must not inherit Website Chat UI instructions.

## Assistant channel behavior policy

- Website Chat (`chat`, `website`, `website_chat`, `web_widget`): may keep the current new-conversation behavior and website widget instructions.
- WhatsApp (`whatsapp`): continues in the same thread, records booking changes as pending requests, never mentions website widget controls.
- Future Phone AI (`phone`, `call`, `voice`): placeholder policy for natural same-interaction handling, without Website Chat UI instructions.

## Security

Twilio signature validation is implemented with `Twilio\Security\RequestValidator`.

Required env:

```text
TWILIO_AUTH_TOKEN=
TWILIO_VALIDATE_SIGNATURE=true
```

Local/test environments can disable strict validation with:

```text
TWILIO_VALIDATE_SIGNATURE=false
```

## Not included in this MVP

- Automatic WhatsApp sender provisioning.
- Meta onboarding.
- Template management.
- Campaigns or broadcasts.
- Media, voice notes, or attachments.
- Phone AI or Telnyx.
- Human handover.
- Status callback endpoint.

WhatsApp AI replies are synchronous in the webhook for the MVP. If latency becomes a problem with Twilio webhook timeouts, move reply generation and sending to a queue while keeping MessageSid deduplication.
