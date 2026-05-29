# WhatsApp AI foundation

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

## Webhook routing

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

## Persistence

WhatsApp uses the existing conversation tables:

- `conversations.channel = whatsapp`
- `conversations.provider = twilio`
- `conversations.external_contact_id = From`
- `conversations.external_sender = To`
- `conversation_messages.provider_message_id = MessageSid`
- `conversation_messages.direction = inbound` or `outbound`

This keeps WhatsApp conversations compatible with Dashboard -> Conversations.

## Usage tracking

The foundation records:

- `whatsapp_conversation`
- `whatsapp_message_inbound`
- `whatsapp_message_outbound`

Future AI replies should count outbound assistant messages against `monthly_whatsapp_messages`.

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
- Full AI replies over WhatsApp.

AI reply integration is the next phase. The foundation currently saves inbound messages, supports outbound test messages, and exposes a post-activation AI toggle.
