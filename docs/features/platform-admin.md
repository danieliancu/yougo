# Platform Admin

Platform Admin is an internal read-only operations area for YouGo operators.

Routes are served inside the same Laravel/Inertia app under:

```text
/platform-admin
```

Platform Admin has a dedicated login page:

```text
/platform-admin/login
```

Every admin route is protected by the `platform_admin` middleware. Guests and normal business users are redirected to `/platform-admin/login` unless they are signed in with the dedicated Platform Admin session.

The MVP uses the same Laravel/Inertia app, but Platform Admin authentication is separate from business authentication. There is no `admin.yougo.ro` or separate deployed app.

## Access

Platform Admin uses the dedicated `platform_admins` table, not normal business users.

The migration creates one default admin account:

```text
username: admin
password: admin
```

Operators can change this username and password from:

```text
/platform-admin/settings
```

You can also create or update a Platform Admin account with:

```bash
php artisan yougo:make-platform-admin admin --password=admin
```

The normal business dashboard no longer links to Platform Admin. The normal business login at `/login` remains unchanged and cannot authenticate Platform Admin accounts.

## Pages

- Overview: business totals, plan mix, WhatsApp status counts, current-month WhatsApp messages, AI bookings, website chat conversations, and Phone AI as planned.
- Businesses: searchable, filterable business list by name/email, plan, subscription status, and WhatsApp status.
- Business detail: profile, billing, usage vs limits, WhatsApp technical details, latest setup request, recent activity, and a copyable activation command.
- WhatsApp Onboarding: requested integrations, setup call details, Meta/account answers, checklist, and copy activation command.
- Usage: current-month usage vs limits with near-limit and reached-limit warnings.
- Issues: read-only queues for WhatsApp requested, active integrations with AI disabled, active integrations with no sender, failed or undelivered WhatsApp messages, missing notification email, usage warnings, and failed jobs when the table is available.
- Admin Settings: change the dedicated Platform Admin username and password.

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
```

Existing WhatsApp AI queue workers and delivery status callbacks remain unchanged.

Keep the existing worker running:

```bash
php artisan queue:work --queue=whatsapp,default --tries=3 --timeout=120
```
