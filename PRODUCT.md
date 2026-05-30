# YouGo Product Context

## Register

product

## Product Purpose

YouGo is an AI receptionist platform for appointment-based businesses. It helps small teams answer website chat questions, collect appointment requests, manage bookings, and monitor usage from a dashboard. The product is currently focused on Appointment Mode, web widget chat, AI booking requests, dashboard workflows, notifications, and local plan-limit testing.

## Primary Users

Business owners, clinic or salon managers, reception teams, and solo service providers who miss calls or messages while serving customers. They need practical tools that reduce admin work without feeling risky, overcomplicated, or like a payment system is already live before it is.

## Audience Mindset

Users are busy and operational. They scan dashboards between appointments, compare plans quickly, and need to trust that limits, bookings, and notifications are clear. They are not looking for a decorative SaaS landing-page experience inside the app. The interface should feel calm, direct, and dependable.

## Brand Tone

Clear, pragmatic, warm, and business-focused. Avoid hype. Explain future features honestly. When a feature is not implemented yet, say so plainly. Romanian and English copy should feel native enough for Romanian market positioning while remaining understandable to English users.

## Product Principles

- Make the working product visible first: bookings, conversations, widget, usage, and setup.
- Keep dashboard UI dense but readable, with predictable controls and familiar table/card patterns.
- Distinguish active capabilities from future positioning. WhatsApp AI is available as an MVP with manual Twilio activation. Phone AI and Telnyx remain future surfaces unless implemented.
- Preserve the Free plan as a stable test and onboarding path.
- Use plan limits honestly. Do not imply unlimited usage unless the backend config and enforcement support it.
- Prefer simple, inspectable UI over clever effects.

## Visual Direction

YouGo should feel like a modern operational tool: restrained colors, clear hierarchy, practical spacing, compact controls, and strong readability. Indigo can remain the primary accent for actions and selected states. Green should signal included/connected/success states. Red should be reserved for warnings, destructive actions, or discounts where explicitly requested.

## Anti-References

- Generic AI SaaS pages with oversized gradients and vague claims.
- Overly decorative dashboards that slow down scanning.
- Pricing tables that hide real limits or overpromise unavailable channels.
- Heavy card grids where a table or compact comparison would work better.
- Complex custom controls that behave differently from standard web UI.

## Current Constraints

- No Stripe integration or real payments yet.
- WhatsApp AI MVP is implemented with manual Twilio activation, inbound webhooks, AI replies, booking requests, booking change requests, dashboard conversations, and usage tracking.
- No Phone AI or Telnyx implementation yet.
- Chat means text input in website chat; AI replies in text.
- Usage tracking currently enforces conversations, AI messages, and bookings.
- Pricing and Billing UI may display future capabilities, but enforcement should remain limited to implemented tracked fields.
