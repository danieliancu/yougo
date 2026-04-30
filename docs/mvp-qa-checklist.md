# YouGo MVP QA Checklist

Use this checklist before deploying the Appointment Mode MVP.

## Account and Setup

1. Register a new account.
2. Confirm the account opens the onboarding dashboard.
3. Complete or skip onboarding and verify both paths return to the dashboard.
4. Add at least one location with valid opening hours.
5. Add at least one service with duration, price, and location assignment.
6. Add at least one staff member and assign services/locations.
7. Configure AI Settings: assistant name, language mode, tone, business context, and booking settings.

## Assistant Preview

8. Open `/assistant/{salon}` from the Widget or Onboarding section.
9. Ask a general business question and confirm the assistant responds without technical errors.
10. Ask for availability on a valid date.
11. Ask for a preferred time and confirm the assistant says whether it is available.
12. Ask for a time after a given hour and confirm alternatives are returned.
13. Create a booking request with service, location, date, time, and client details.
14. Confirm the booking appears in Dashboard > Bookings with the expected pending status.
15. Confirm the booking notification behavior: email is sent, logged, or safely skipped based on local mail setup.
16. In the same conversation, ask for another booking and confirm the assistant asks the user to press `+` and start a new conversation.
17. Press `+` and confirm a fresh conversation starts.

## Widget

18. Open Dashboard > Widget and confirm preview, embed code, settings, and readiness guidance are visible.
19. Copy the embed script.
20. Test `/widget/{widgetKey}` directly.
21. Create a simple local HTML page with the embed script and confirm the widget opens.
22. Test with allowed domains empty and confirm chat works.
23. Add a specific allowed domain and confirm other domains are rejected with a friendly message.

## Usage, Plans, and Limits

24. Open Dashboard > Billing and confirm payments are clearly marked as not connected.
25. Change the temporary local testing plan and confirm usage limits update.
26. Trigger a conversation or AI message limit and confirm the limit message is friendly.
27. Trigger a booking limit and confirm no extra booking is created.

## Language

28. Set display language / AI language mode to RO and test availability, booking confirmation, limit, and existing-booking messages.
29. Set display language / AI language mode to EN and repeat the same checks.
30. Confirm dashboard empty states and widget guidance read consistently in the selected UI language.

## Regression Pass

31. Confirm locations, services, staff, bookings, conversations, widget, billing, onboarding, and settings pages still load.
32. Confirm staff availability and capacity rules still affect returned slots.
33. Run `php artisan test`.
34. Run `npm run build`.
