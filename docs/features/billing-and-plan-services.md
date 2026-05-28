# Billing and plan services

Dashboard -> Billing is the place where YouGo shows the current plan, usage limits, upgrade options and the services included in the plan.

The service entitlement overview is shown in Billing, not Settings. It uses `config/yougo_services.php` and `App\Support\YouGoServices` through the existing dashboard billing payload.

Paid plan upgrades and subscription management happen through Stripe Checkout and Stripe Customer Portal. Free is internal only and does not require Stripe or a card. Paid plan activation happens after Stripe confirms the subscription by webhook.

Current services:

- `website_chat`: live website chat channel.
- `whatsapp_ai`: planned WhatsApp AI channel.
- `phone_ai`: planned Phone AI channel.

Settings remains focused on business configuration: profile details, localization, notifications and assistant preferences. Billing owns plan capabilities, usage and included service/channel status.

Planned services must stay visibly planned until their `implementation_status` changes in the service catalog. Do not imply WhatsApp AI or Phone AI are live from the Billing UI alone.
