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

## What It Must Not Claim

It must not claim WhatsApp AI or Phone AI/Telnyx/demo phone are live while they remain planned. Stripe billing is live for paid plan checkout and subscription management only. It must not expose API keys, env variables, prompts, private account data or internal implementation secrets.

## Future Phone Demo Reuse

The knowledge formatter is intentionally separate from the text chat controller so a future phone demo assistant can reuse the same product facts. No Telnyx, STT, TTS or phone demo route is implemented yet.
