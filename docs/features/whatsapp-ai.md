# WhatsApp AI

WhatsApp AI is available as an MVP. It supports Twilio inbound webhooks, automatic AI replies, booking creation, pending booking cancellation, dashboard conversations, usage limits, delivery status callbacks, and manual real-sender activation per salon.

## Provider

WhatsApp AI uses Twilio as the WhatsApp provider.

The MVP does not provision Meta or Twilio senders automatically. It stores the business request and lets the YouGo team configure the sender manually in Twilio/Meta. Customer-facing dashboard copy should say that YouGo will configure the WhatsApp number, not expose Twilio sender details.

Phone AI remains planned and is not part of the WhatsApp MVP.

## MVP activation flow

1. The business owner opens Dashboard -> WhatsApp Settings.
2. They enter their WhatsApp Business number in international format, for example `+407...` or `+447...`.
3. They click Request activation to reveal the setup guidance and setup-call details form.
4. They submit the setup-call details.
5. YouGo stores `whatsapp_integrations.requested_number`, sets `status = requested`, records `requested_at`, and saves sanitized setup metadata.
6. A YouGo admin configures the WhatsApp sender in Twilio.
7. The admin manually activates the integration.
8. Once active, the business owner can toggle WhatsApp AI on or off.

After the business requests activation, Dashboard -> WhatsApp Settings explains the guided setup process and lets the business request a setup call. YouGo receives the setup request by email at the configured `MAIL_WHATSAPP_SETUP_REQUEST_TO` address, which defaults to `dani.iancu@yahoo.com`.

The latest sanitized setup request is also visible to promoted operators in Platform Admin under `/platform-admin/whatsapp-onboarding`. The requested queue only includes businesses that submitted the setup-call details form. Platform Admin can display setup-call details and a copyable manual activation command, but it does not provision Twilio or Meta senders automatically.

Security rules for setup:

- Customers should never send Facebook, Meta, or WhatsApp passwords to YouGo.
- YouGo should never ask for authentication codes or two-factor codes.
- If Meta/Facebook login is needed, the customer logs in themselves during the guided setup call.
- A video call is recommended because Meta steps may need to be completed together, but the customer can request a phone call.

Activation states:

- `needs activation`: the plan includes WhatsApp AI, but the business has not requested setup yet.
- `activation requested`: the business entered a WhatsApp number and is waiting for YouGo admin setup.
- `active`: the WhatsApp sender is configured and WhatsApp AI can be toggled on or off.
- `disabled`: the integration exists but is disabled.
- `failed`: activation failed and needs admin review.

Manual activation:

```bash
php artisan yougo:whatsapp-activate {salon_id} {twilio_sender}
```

The command accepts `whatsapp:+407...`, `+407...`, or `00407...`, normalizes the stored sender to `whatsapp:+407...`, and can take an optional display number:

```bash
php artisan yougo:whatsapp-activate {salon_id} whatsapp:+407... --display-number="+40 7xx xxx xxx"
```

Activation behavior:

1. Set `whatsapp_integrations.twilio_sender = 'whatsapp:+40...'`.
2. Set `display_number` from `--display-number` or the normalized `+...` number.
3. Set `status = 'active'`.
4. Set `activated_at = now()`.
5. Preserve `requested_number`.
6. Merge activation metadata such as `activation_source = manual` and `activated_by = command`.
7. Refuse to activate a sender already assigned to another salon.

Admin checklist:

1. Review the requested number in YouGo.
2. Review the setup request email and schedule a video or phone setup call.
3. Configure and approve the WhatsApp sender in Twilio/Meta manually.
4. Verify the sender is live.
5. Run `php artisan yougo:whatsapp-activate {salon_id} whatsapp:+...`.
6. Confirm the dashboard shows the integration as active.
7. Test one inbound and one outbound WhatsApp message.
8. Ensure the queue worker and delivery status callback URL are active.

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

## Delivery status callbacks

Outbound Twilio WhatsApp messages can include a status callback URL:

```text
TWILIO_WHATSAPP_STATUS_CALLBACK_URL=https://your-domain.com/twilio/whatsapp/status
```

When configured, YouGo passes this URL as `statusCallback` on outbound Twilio API sends. Twilio then sends delivery updates to:

```text
POST /twilio/whatsapp/status
```

The status callback endpoint is separate from the inbound webhook. It validates the Twilio signature when `TWILIO_VALIDATE_SIGNATURE=true`, finds the outbound message by Twilio `MessageSid`, and saves delivery metadata on the existing `conversation_messages` row.

Delivery metadata includes:

- `metadata.delivery_status`
- `metadata.delivery.status`
- `metadata.delivery.raw_status`
- `metadata.delivery.updated_at`
- `metadata.delivery.error_code`
- `metadata.delivery.error_message`
- `metadata.delivery.history`

The dashboard can show `queued`, `accepted`, `sending`, `sent`, `delivered`, `read`, `failed`, `undelivered`, or `unknown` when Twilio provides those updates. Duplicate status callbacks are ignored for history growth, and history is bounded.

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

Failed jobs use Laravel's configured failed job storage.

Deployment notes:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Ensure the queue worker keeps running:

```bash
php artisan queue:work --queue=whatsapp,default --tries=3 --timeout=120
```

The API-level `statusCallback` should be enough for outbound messages. If Twilio Console also requires a sender-level callback, set the same HTTPS production URL under the WhatsApp sender or messaging status callback settings.

## Supported message types

The MVP supports customer-initiated text messages only.

Media-only messages are stored, but the automatic reply tells the customer that YouGo currently processes WhatsApp text messages only.

Unsupported in this phase:

- message templates
- campaigns or broadcasts
- images, audio, video, documents or voice notes
- human handover

## Post-booking behavior

WhatsApp continues in the same customer phone thread, but YouGo dashboard conversations are separated by operational booking flow.

The assistant must not ask WhatsApp customers to press the website widget new-conversation control, start a new conversation, or open a new chat.

One active booking flow maps to one dashboard conversation:

- If the latest WhatsApp dashboard conversation has no booking, new inbound messages continue that conversation.
- If it has a `pending` WhatsApp booking, new inbound messages continue that conversation.
- If it has a `confirmed`, `completed`, `cancelled`, or archived booking, short courtesy messages such as thanks/ok may stay in the old transcript.
- If it has a `confirmed`, `completed`, `cancelled`, or archived booking and the inbound message looks operational (new booking, service request, edit, cancellation, date/time question, or booking intent), the old conversation is closed and the inbound message starts a new dashboard conversation.

The actual booking statuses are `pending`, `confirmed`, `cancelled`, and `completed`. The dashboard archive is based on `booking.date < today`, not a separate booking status.

When a new WhatsApp dashboard conversation is created for the same real customer phone and has no `booking_id`, the AI prompt can include read-only context about the latest relevant booking for that same salon and phone number. This lets the assistant answer questions like "was my booking confirmed?" using the dashboard booking status instead of a generic pending-booking explanation.

This previous booking context:

- matches by normalized WhatsApp/customer phone inside the same salon;
- can include `pending`, `confirmed`, `cancelled`, and `completed` bookings;
- includes status, date, time, service, location, staff, client name, and phone when available;
- does not attach `booking_id` to the new conversation;
- does not reopen the old dashboard conversation;
- does not allow automatic edits, reschedules, or cancellations for confirmed, completed, cancelled, or historical bookings.

Dashboard booking status remains the source of truth. Previous booking context is only prompt context for accurate answers.

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

WhatsApp AI replies are queued from the webhook. Delivery status callbacks are supported for outbound Twilio WhatsApp messages.
