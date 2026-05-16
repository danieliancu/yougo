# YouGo Service Catalog Architecture

## 1. Purpose

YouGo has one technical service catalog for commercial service channels. It defines what services exist, how they are displayed, and whether they are live or planned.

## 2. Runtime Source Of Truth

`config/yougo_services.php` is the runtime service catalog.

`config/yougo_plans.php` uses `service_keys` to define which services each plan includes. This is the canonical plan entitlement mapping.

## 3. Documentation Role

This document explains the rules and must be consulted before changing services or plans. The app reads PHP config, not this Markdown file.

## 4. Current Services

- `website_chat`: public website text chat for conversations and AI booking requests.
- `whatsapp_ai`: WhatsApp conversations, automatic replies, and AI booking requests.
- `phone_ai`: phone call answering, voice replies, and request collection.

## 5. Service Statuses

- `live`: implemented and available when the plan includes it.
- `planned`: commercially represented, but not implemented as a live product capability yet.

## 6. Plan Entitlement Rules

`service_keys` is canonical. UI and backend entitlement logic must derive service availability from `service_keys` plus the service catalog.

## 7. Deprecated Legacy Fields

`widgets_enabled`, `whatsapp_enabled`, `phone_enabled`, `channels`, and `features` may remain in plan config for compatibility with older code, historical tests, or existing payload shape.

New code must not use those fields to decide whether a service exists or whether a plan includes a service.

## 8. Adding A New Service

1. Add the service to `config/yougo_services.php`.
2. Add translation keys for title, subtitle, and short label.
3. Add frontend icon support for the configured icon key.
4. Add the service key to the relevant plans in `config/yougo_plans.php`.
5. Update tests for service schema, plan mapping, and UI rendering.
6. Do not hardcode the new service directly in React UI.

## 9. Adding Or Changing A Plan

1. Update `config/yougo_plans.php`.
2. Set the plan's `service_keys`.
3. Validate that every service key exists in the catalog.
4. Avoid duplicating service logic in UI components.

## 10. UI Rules

- Render services from the catalog.
- Show implementation status honestly.
- Show plan entitlement separately from implementation status.
- A planned included service must not look live.
- Website Chat is text chat only. Do not reintroduce website voice input.

## 11. Backend Rules

- Use `App\Support\YouGoServices` for catalog and entitlement decisions.
- Do not read raw legacy plan fields in new code.
- Validate unknown service keys before exposing plans.

## 12. Testing Rules

- Test every plan `service_key` exists.
- Test dashboard integrations render from catalog data.
- Test landing pricing uses `service_keys`.
- Test planned/live statuses display correctly.

## 13. Current Compatibility Notes

- `widgets_enabled`, `whatsapp_enabled`, `phone_enabled`, `channels`, and `features` remain in `config/yougo_plans.php` only to preserve older payload shape and avoid breaking consumers during the transition. New code may not use them for service availability, service display, or plan entitlement decisions. Remove them only after all consumers and tests no longer depend on their presence.
- `/dashboard/chat-audio` remains as a compatibility redirect to `/dashboard/widget`. New navigation and controller sections must not link to or render a `chat-audio` dashboard surface.
- Historical `voice_input_used` migrations remain to preserve migration history. They do not imply website voice input support.
- Website Chat is text-only. Browser microphone APIs, `MediaRecorder`, speech recognition, transcribe routes, and `Chat + Voice` labels must stay out of public website chat.
