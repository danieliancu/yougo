# Platform Admin

Platform Admin is an internal read-only operations area for YouGo operators.

Routes are served inside the same Laravel/Inertia app under:

```text
/platform-admin
```

Every route is protected by the `platform_admin` middleware. A user must be authenticated and have `users.is_platform_admin = true`; otherwise the request returns `403`.

The MVP uses the existing app login. There is no `admin.yougo.ro`, separate app, or separate admin login system.

## Access

Promote an existing operator user with:

```bash
php artisan yougo:make-platform-admin admin@example.com
```

The normal business dashboard shows a discreet `Platform Admin` link only to promoted users. The link is convenience only; access is enforced on the backend.

Deployments must run the migration that adds `users.is_platform_admin` before an operator can be promoted.

## Pages

- Overview: business totals, plan mix, WhatsApp status counts, current-month WhatsApp messages, AI bookings, website chat conversations, and Phone AI as planned.
- Businesses: searchable, filterable business list by name/email, plan, subscription status, and WhatsApp status.
- Business detail: profile, billing, usage vs limits, WhatsApp technical details, latest setup request, recent activity, and a copyable activation command.
- WhatsApp Onboarding: requested integrations, setup call details, Meta/account answers, checklist, and copy activation command.
- Usage: current-month usage vs limits with near-limit and reached-limit warnings.
- Issues: read-only queues for WhatsApp requested, active integrations with AI disabled, active integrations with no sender, failed or undelivered WhatsApp messages, missing notification email, usage warnings, and failed jobs when the table is available.

The frontend uses separate Inertia pages for these routes under `resources/js/Pages/PlatformAdmin`. Shared layout and table helpers keep the console visually distinct from the business dashboard.

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

Technical sender fields such as `twilio_sender` are exposed only in Platform Admin payloads. They are not returned in the normal customer-facing WhatsApp dashboard JSON or Inertia payload.

## WhatsApp Activation

The WhatsApp Onboarding requested queue includes integrations only after the business submits the setup-call details form. Clicking the first dashboard activation button only reveals setup guidance and does not create an admin queue item.

Platform Admin generates the manual activation command from the requested number when possible:

```bash
php artisan yougo:whatsapp-activate {salon_id} whatsapp:+...
```

This does not provision Twilio or Meta automatically. Operators still configure and approve the sender externally, then run the command.

## Deployment

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan yougo:make-platform-admin admin@example.com
```

Existing WhatsApp AI queue workers and delivery status callbacks remain unchanged.

Keep the existing worker running:

```bash
php artisan queue:work --queue=whatsapp,default --tries=3 --timeout=120
```
