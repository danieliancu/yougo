# Resend Email

Resend is used only as the outbound email provider. YouGo still decides who receives booking notifications from the salon settings.

## Forge Environment

Set these variables in Forge:

```env
MAIL_MAILER=resend
RESEND_KEY=
MAIL_FROM_ADDRESS=notifications@your-verified-domain.com
MAIL_FROM_NAME="YouGo"
```

Get `RESEND_KEY` from the Resend dashboard. `MAIL_FROM_ADDRESS` is the sender address and must use a domain verified in Resend.

Booking notifications are sent to `salon.notification_email`, configured in Dashboard -> Settings -> Notificari -> Email notificari. `MAIL_FROM_ADDRESS` is not the booking notification recipient.

## Test Sending

```bash
php artisan yougo:test-email your@email.com
```

To test the AI booking notification flow, create a booking through the AI assistant for a salon with:

- `salon.notification_email` filled in
- `email_notifications` enabled
- `booking_confirmations` enabled

## Common Issues

- Resend domain is not verified
- `MAIL_FROM_ADDRESS` is not on a verified Resend domain
- `RESEND_KEY` is missing or wrong
- `salon.notification_email` is empty
- `email_notifications` is disabled
- `booking_confirmations` is disabled
- Laravel config cache was not cleared after changing env values
