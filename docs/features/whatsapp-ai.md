# WhatsApp AI

WhatsApp AI is available as an MVP. It supports Twilio inbound webhooks, automatic AI replies, booking creation, pending booking cancellation, dashboard conversations, usage limits, and manual Twilio activation.

## Provider

WhatsApp AI uses Twilio as the WhatsApp provider.

The MVP does not provision Meta or Twilio senders automatically. It stores the business request and lets the YouGo team configure the sender manually in Twilio.

Phone AI remains planned and is not part of the WhatsApp MVP.

## MVP activation flow

1. The business owner opens Dashboard -> WhatsApp Settings.
2. They enter their business WhatsApp number.
3. They click Request activation.
4. YouGo stores `whatsapp_integrations.requested_number`, sets `status = requested`, and records `requested_at`.
5. A YouGo admin configures the WhatsApp sender in Twilio.
6. The admin manually activates the integration.
7. Once active, the business owner can toggle WhatsApp AI on or off.

Activation states:

- `needs activation`: the plan includes WhatsApp AI, but the business has not requested setup yet.
- `activation requested`: the business entered a WhatsApp number and is waiting for YouGo admin setup.
- `active`: the Twilio sender is configured and WhatsApp AI can be toggled on or off.
- `disabled`: the integration exists but is disabled.
- `failed`: activation failed and needs admin review.

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

## Webhook routing and queued AI replies

Twilio sends inbound WhatsApp messages to:

```text
POST /twilio/whatsapp/webhook
```

The webhook maps Twilio `To` to `whatsapp_integrations.twilio_sender`, then stores the inbound message on the matching salon. It dispatches reply generation to the queue and returns empty TwiML immediately, so Twilio does not wait on Gemini or outbound send latency.

Webhook payload fields used:

- `From`
- `To`
- `Body`
- `MessageSid`
- `ProfileName`
- `WaId`
- `NumMedia`

`MessageSid` is used for deduplication.

When the integration is active, the salon plan includes `whatsapp_ai`, and `ai_enabled = true`, YouGo replies automatically to customer-initiated text messages through `ProcessWhatsAppInboundMessage` on the `whatsapp` queue.

The WhatsApp reply flow:

1. Saves the inbound Twilio message.
2. Dispatches `ProcessWhatsAppInboundMessage` for valid text or unsupported-media fallback.
3. Returns `<Response></Response>` with HTTP 200.
4. The job reloads the salon, integration, inbound message and conversation from the database.
5. The job re-checks integration status, `ai_enabled`, plan entitlement and inbound idempotency.
6. The job reuses the same business assistant flow as website chat.
7. Adds WhatsApp-specific prompt instructions for short, natural replies.
8. Uses configured business data: services, prices, locations, staff, opening hours and localization.
9. Allows the existing appointment tools to create booking requests.
10. Runs a deterministic WhatsApp outbound guard before Twilio send so website chat UI instructions such as pressing `+`, starting a new conversation, opening a new chat, or using a separate conversation are replaced with a safe WhatsApp-specific reply.
11. Sends the guarded AI reply back through Twilio.
12. Saves the outbound message in the conversation transcript.

If the assistant creates a booking request, the existing booking notification email behavior is used.

For WhatsApp bookings, Twilio `From` is treated as the customer's phone number. YouGo strips the `whatsapp:` prefix before using it as `client_phone`, tells the assistant not to ask for the phone again unless it is missing or invalid, and fills `client_phone` automatically if the model calls `bookBooking` without it.

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

Inbound message metadata tracks queued reply processing:

- `ai_reply_job_dispatched_at`
- `ai_reply_processing_started_at`
- `ai_reply_processed_at`
- `ai_reply_failed_at`
- `ai_reply_attempts`
- `ai_reply_last_error`
- `ai_reply_mode`

Outbound WhatsApp AI and fallback messages include `inbound_message_id` and `inbound_provider_message_id`. The job checks these fields before generating a reply. If an inbound message is already processed or an outbound message already references it, the job logs a duplicate skip and exits.

## Usage tracking

The foundation records:

- `whatsapp_conversation`
- `whatsapp_message_inbound`
- `whatsapp_message_outbound`
- `whatsapp_ai_reply`

Inbound and outbound WhatsApp messages count toward `monthly_whatsapp_messages`.

`whatsapp_conversation` is kept as an analytics event for understanding WhatsApp conversation volume. It is not a separate plan limit and must not be displayed as a billable usage row unless a future `monthly_whatsapp_conversations` limit is added to plan config.

If the monthly WhatsApp message limit is reached, YouGo does not call the AI. It can send a short fallback asking the customer to contact the business directly.

## Queue operations

Production must run a Laravel queue worker for WhatsApp AI replies.

The project default queue connection is database unless overridden:

```text
QUEUE_CONNECTION=database
```

If database queues are used, ensure the `jobs` and `failed_jobs` tables exist by running migrations.

Forge daemon example:

```bash
php artisan queue:work --queue=whatsapp,default --tries=3 --timeout=120
```

After deploys, restart workers:

```bash
php artisan queue:restart
```

Failed jobs use Laravel's configured failed job storage. Twilio status callbacks are intentionally not part of this queued reply hardening task.

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

WhatsApp continues in the same customer phone thread, but YouGo dashboard conversations are separated by operational booking flow.

The assistant must not ask WhatsApp customers to press the website widget new-conversation control, start a new conversation, or open a new chat.

One active booking flow maps to one dashboard conversation:

- If the latest WhatsApp dashboard conversation has no booking, new inbound messages continue that conversation.
- If it has a `pending` WhatsApp booking, new inbound messages continue that conversation.
- If it has a `confirmed`, `completed`, `cancelled`, or archived booking, short courtesy messages such as thanks/ok may stay in the old transcript.
- If it has a `confirmed`, `completed`, `cancelled`, or archived booking and the inbound message looks operational (new booking, service request, edit, cancellation, date/time question, or booking intent), the old conversation is closed and the inbound message starts a new dashboard conversation.

The actual booking statuses are `pending`, `confirmed`, `cancelled`, and `completed`. The dashboard archive is based on `booking.date < today`, not a separate booking status.

Pending WhatsApp bookings can be cancelled automatically when the customer clearly asks to cancel. YouGo sets `booking.status = cancelled`, stores cancellation context in conversation metadata, sends the business a cancellation email when booking notifications are configured, tells the customer the booking was cancelled, and closes the dashboard conversation.

Pending bookings cannot be edited or rescheduled automatically. Confirmed, completed, cancelled, and archived bookings cannot be edited, rescheduled, or cancelled automatically by AI. If the customer asks to change an existing booking, YouGo sends a phone handoff message with available salon/location phone numbers when configured.

Website Chat follows the same booking status policy for existing bookings. A pending AI-created Website Chat booking can be cancelled automatically by clear customer intent. Edit/reschedule/change requests receive phone handoff. The `+` instruction remains available only for starting a new booking or separate flow, not as the answer to every post-booking issue.

The old amendment workflow is deprecated. YouGo no longer creates new `booking_change_requests` metadata for WhatsApp edits/reschedules, no longer sends booking change request emails for those attempts, and no longer shows amendment cards or "Change resolved" actions in Conversations or Bookings. Existing historical metadata is left in place but is not shown as an active workflow.

Dashboard -> Conversations is transcript-only for this workflow. Dashboard -> Bookings -> List shows normal booking statuses only. Dashboard -> Bookings -> Archive is read-only for operational actions: no edit, cancel, amend, or resolve actions are shown there.

Full automatic rescheduling/editing and automatic multi-booking inside one dashboard conversation remain future work.

Future Phone AI must use its own channel behavior policy and must not inherit Website Chat UI instructions.

## Assistant channel behavior policy

- Website Chat (`chat`, `website`, `website_chat`, `web_widget`): may keep the current new-conversation behavior and website widget instructions.
- WhatsApp (`whatsapp`): continues in the same customer phone thread, separates dashboard conversations by booking flow, allows deterministic cancellation only for pending WhatsApp bookings, uses phone handoff for edits/reschedules, and never mentions website widget controls.
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

WhatsApp AI replies are queued from the webhook. Status callbacks remain future work.
