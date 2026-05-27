# Email Notifications

YouGo can send email notifications to the business owner for key booking events.

## Settings location

Dashboard → Settings → Notifications → Email notifications

## Toggles

### New bookings (`booking_confirmations`)

Sends an email to the business `notification_email` when the AI assistant creates a new booking request.

- Controlled by the **Programări noi / New bookings** toggle.
- Only fires for bookings with `source = 'ai_assistant'`.
- Idempotent: only sends once per booking (tracked by `notification_sent_at`).
- Mail class: `App\Mail\NewAiBookingMail`

### Booking status changes (`booking_status_email_notifications`)

Sends an email to the business `notification_email` when a booking status changes.

- Controlled by the **Schimbări status programare / Booking status changes** toggle.
- Fires when a booking transitions between any of: `pending`, `confirmed`, `cancelled`, `completed`.
- Does not fire when status stays the same.
- Does not fire on initial booking creation.
- Mail class: `App\Mail\BookingStatusChangedMail`

## Recipient

All emails go to `salon.notification_email`. Customer-facing emails are not implemented.

## Plan gating

Both toggles are available on all plans (including Free) as long as `email_notifications_enabled` is true in `config/yougo_plans.php`. The `paidEmailSettingsAvailable` flag in the UI controls whether the toggles are interactive.

## Internal field `email_notifications`

The `email_notifications` database column is derived automatically: it is set to `true` when at least one email toggle is enabled, `false` when both are disabled. It is not exposed as a standalone toggle in the UI.

## Database fields (salons table)

| Field | Type | Default | Purpose |
|---|---|---|---|
| `notification_email` | string nullable | — | Recipient address for all business notifications |
| `booking_confirmations` | boolean | true | New booking email toggle |
| `booking_status_email_notifications` | boolean | false | Status-change email toggle |
| `email_notifications` | boolean | true | Derived master switch (managed by SettingsController) |
