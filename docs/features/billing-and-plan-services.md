# Billing and plan services

Dashboard -> Billing is the place where YouGo shows the current plan, usage limits, upgrade options and the services included in the plan.

The service entitlement overview is shown in Billing, not Settings. It uses `config/yougo_services.php` and `App\Support\YouGoServices` through the existing dashboard billing payload.

Paid plan upgrades and subscription management happen through Stripe Checkout and Stripe Customer Portal. Free is internal only and does not require Stripe or a card. Paid plan activation happens after Stripe confirms the subscription by webhook.

Annual billing is hidden until separate Stripe annual price IDs exist. Billing currently presents and sells monthly subscriptions only.

Current services:

- `website_chat`: live website chat channel.
- `whatsapp_ai`: planned WhatsApp AI channel.
- `phone_ai`: planned Phone AI channel.

Settings remains focused on business configuration: profile details, localization, notifications and assistant preferences. Billing owns plan capabilities, usage and included service/channel status.

Planned services must stay visibly planned until their `implementation_status` changes in the service catalog. Do not imply WhatsApp AI or Phone AI are live from the Billing UI alone.

## Usage limits

Dashboard usage must match `config/yougo_plans.php` exactly:

- `monthly_conversations` = website chat conversations started.
- `monthly_ai_messages` = website/chat AI messages.
- `monthly_bookings` = AI-created booking requests from website chat and WhatsApp.
- `monthly_whatsapp_messages` = inbound + outbound WhatsApp messages.
- `monthly_phone_minutes` = Phone AI minutes.

The `whatsapp_conversation` usage event is analytics only. It is not a separate billable plan limit unless a future `monthly_whatsapp_conversations` config field is intentionally added.
