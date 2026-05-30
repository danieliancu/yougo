# Stripe billing

Dashboard -> Billing uses Stripe Checkout and Stripe Customer Portal for paid YouGo subscriptions.

Free is an internal YouGo plan only. It does not exist in Stripe, does not require a card, and never creates a Checkout Session.

Paid internal plan keys:

- `website_chat`: Website Chat
- `chat_whatsapp`: Chat + WhatsApp
- `voice_starter`: YouGo Starter
- `voice_growth`: YouGo Growth
- `voice_pro`: YouGo Pro

Required environment variables in Forge or `.env`:

- `STRIPE_KEY`
- `STRIPE_SECRET`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_PRICE_WEBSITE_CHAT`
- `STRIPE_PRICE_CHAT_WHATSAPP`
- `STRIPE_PRICE_VOICE_STARTER`
- `STRIPE_PRICE_VOICE_GROWTH`
- `STRIPE_PRICE_VOICE_PRO`

Webhook URL:

- `/stripe/webhook`

Handled Stripe events:

- `checkout.session.completed`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.payment_succeeded`
- `invoice.payment_failed`

Plan updates are webhook-driven. Creating a Checkout Session never activates a paid plan. YouGo updates `salons.plan` only after Stripe confirms an active or trialing subscription.

Safety rule: the Stripe subscription item `price_id` maps to the internal plan key through `config/stripe.php`. Metadata can help find the salon, but metadata is not trusted to choose the plan.

If payment fails, YouGo marks the subscription with a payment problem and keeps the current paid plan temporarily. If Stripe later sends a canceled/deleted subscription event, YouGo downgrades the business to `free`.

The Customer Portal is available from Billing when a Stripe customer or subscription exists. Users manage payment method, invoices, plan cancellation, and subscription details in Stripe.

Annual billing is intentionally disabled in the UI for now. The current Stripe integration supports monthly subscriptions only. Re-enabling annual billing requires separate Stripe annual price IDs for every paid plan before the annual toggle or discount messaging is shown again.

Known limitations:

- Stripe is the payment state provider only. Plan capabilities still come from `config/yougo_plans.php`, `service_keys`, and `App\Support\YouGoServices`.
- WhatsApp AI and Phone AI implementation status is independent from Stripe billing.
- Usage limits are not inferred from Stripe. `monthly_conversations`, `monthly_ai_messages`, `monthly_bookings`, `monthly_whatsapp_messages`, and `monthly_phone_minutes` come from `config/yougo_plans.php`.
