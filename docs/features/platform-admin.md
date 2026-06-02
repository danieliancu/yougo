# Platform Admin

Platform Admin is an internal read-only operations area for YouGo operators.

Routes are served inside the same Laravel/Inertia app under:

```text
/platform-admin
```

Every route is protected by the `platform_admin` middleware. A user must be authenticated and have `users.is_platform_admin = true`; otherwise the request returns `403`.

## Access

Promote an existing operator user with:

```bash
php artisan yougo:make-platform-admin admin@example.com
```

The normal business dashboard shows a discreet `Platform Admin` link only to promoted users. The link is convenience only; access is enforced on the backend.

## Pages

- Overview: business totals, plan mix, WhatsApp status counts, current-month WhatsApp messages, AI bookings, website chat conversations, and Phone AI as planned.
- Businesses: searchable, filterable business list by name/email, plan, subscription status, and WhatsApp status.
- Business detail: profile, billing, usage vs limits, WhatsApp technical details, latest setup request, recent activity, and a copyable activation command.
- WhatsApp Onboarding: requested integrations, setup call details, Meta/account answers, checklist, and copy activation command.
- Usage: current-month usage vs limits with near-limit and reached-limit warnings.
- Issues: read-only queues for WhatsApp requested, active integrations with AI disabled, active integrations with no sender, failed or undelivered WhatsApp messages, missing notification email, usage warnings, and failed jobs when the table is available.

## WhatsApp Setup Requests

When a business submits:

```text
POST /dashboard/whatsapp/setup-request
```

YouGo stores sanitized setup-call data in:

```text
whatsapp_integrations.metadata.latest_setup_request
```

Passwords, Meta/Facebook credentials, authentication codes, and two-factor codes are rejected and are not stored.

## WhatsApp Activation

Platform Admin generates the manual activation command from the requested number when possible:

```bash
php artisan yougo:whatsapp-activate {salon_id} whatsapp:+...
```

This does not provision Twilio or Meta automatically. Operators still configure and approve the sender externally, then run the command.

## Deployment

```bash
php artisan migrate --force
php artisan yougo:make-platform-admin admin@example.com
php artisan optimize:clear
```

Existing WhatsApp AI queue workers and delivery status callbacks remain unchanged.
