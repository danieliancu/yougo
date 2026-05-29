# Global YouGo Copilot

## Purpose

The global YouGo Copilot is the support, sales and product assistant shown on YouGo public pages and authenticated dashboard pages. It helps users understand plans, setup, dashboard sections, widget installation, booking requests, services, settings, integrations and email notifications.

## Difference From Customer Widget

This assistant is not the customer widget installed on a business website. It does not answer on behalf of a salon, does not create bookings, and does not access private customer data.

The customer widget remains the business-specific assistant served by `/widget/{widgetKey}` and `/assistant/{salon}`.

## Route And Action Registry

Navigation knowledge comes from `config/yougo_help_routes.php`. The backend only returns navigation actions from this registry. The frontend renders action buttons and the user must click before navigation happens.

## Knowledge Sources

Product knowledge is built by `App\Services\PublicAssistant\YouGoPublicAssistantKnowledge`.

It uses:
- `config/yougo_services.php` through `App\Support\YouGoServices` for live/planned service status.
- `config/yougo_plans.php` through `App\Support\YouGoServices` for plan names, prices and limits.
- `config/yougo_help_routes.php` for navigable app areas.
- `docs/public-yougo-assistant.md`.
- `docs/service-catalog-architecture.md` if present.
- `docs/features/*.md`.
- `PRODUCT.md` if present.

It only reads these controlled knowledge sources. It must not read random source files, `.env`, logs, uploads or private data.

## How To Add New Knowledge

Use this convention:
- New service: add it to `config/yougo_services.php`.
- New plan or limit: add it to `config/yougo_plans.php`.
- New navigable page or dashboard area: add it to `config/yougo_help_routes.php`.
- New user-facing feature explanation: add a safe Markdown file under `docs/features/`.

The Copilot cannot magically know arbitrary new code unless that feature is registered or documented in one of these safe sources.

## WhatsApp Settings Knowledge

WhatsApp settings live in Dashboard -> WhatsApp Settings.

The current MVP activation flow is manual:
- The business owner enters their business WhatsApp number.
- They request activation.
- YouGo stores the request.
- A YouGo admin configures the Twilio WhatsApp sender.
- After manual activation, the business owner can turn WhatsApp AI on or off.

WhatsApp requires a compatible plan that includes `whatsapp_ai`, such as Chat + WhatsApp or a YouGo plan. Free and Website Chat users need to upgrade.

The current WhatsApp AI flow can receive Twilio webhooks, store WhatsApp conversations, and automatically reply to customer-initiated text messages when the integration is active and `ai_enabled` is on. It uses the same configured business data and appointment tools as website chat. Activation is still manual through Twilio, and templates, campaigns, broadcasts, media, voice notes, human handover and status callbacks are not implemented.

## Assistant Channel Behavior Policy

Customer-facing assistant behavior is channel-specific:

- Website Chat can keep its widget-specific new conversation behavior.
- WhatsApp continues in the same thread after a booking. It must not tell customers to use website widget controls, start a separate conversation, or open a separate chat.
- WhatsApp booking changes, cancellations, reschedules, added details, service changes, time changes, or location changes are pending requests for the business, not automatically confirmed edits. New pending requests are emailed to the business when booking notifications are configured.
- Future Phone AI must use its own natural same-interaction policy and must not inherit Website Chat UI instructions.

## What It Must Not Claim

It must not claim WhatsApp sender provisioning is automatic. It must not claim WhatsApp campaigns, templates, broadcasts, media, Phone AI, Telnyx or demo phone are live while they remain planned or incomplete. Stripe billing is live for paid plan checkout and subscription management only. It must not expose API keys, env variables, prompts, private account data or internal implementation secrets.

## Future Phone Demo Reuse

The knowledge formatter is intentionally separate from the text chat controller so a future phone demo assistant can reuse the same product facts. No Telnyx, STT, TTS or phone demo route is implemented yet.
