# Business Localization

YouGo uses the business country as the localization anchor for each salon/business account.

## Purpose

The `salons.country` setting drives business-facing defaults for:

- currency
- phone prefix
- timezone
- date format
- future regional metadata

This is infrastructure for safer regional behavior in the dashboard, AI context, service pricing, email dates, and later telecom/tax work.

## Supported Countries

Supported countries are configured in `config/yougo_locales.php`. The catalog starts with Romania and the United Kingdom, then includes the broader European market such as EU countries, Switzerland, Norway, Iceland, Moldova, Ukraine, the Balkans and other European countries.

Examples:

- `RO`: Romania, `RON`, `+40`, `Europe/Bucharest`, `dd.mm.yyyy`, default language `ro`
- `GB`: United Kingdom, `GBP`, `+44`, `Europe/London`, `dd/mm/yyyy`, default language `en`
- `FR`: France, `EUR`, `+33`, `Europe/Paris`
- `DE`: Germany, `EUR`, `+49`, `Europe/Berlin`
- `CH`: Switzerland, `CHF`, `+41`, `Europe/Zurich`

`UK` is accepted as an alias and normalized to `GB`.

## Current Behavior

Dashboard settings let the owner choose country, timezone, and date format. Country automatically suggests currency, phone prefix, timezone, and date format. Currency and phone prefix are generated from the country and are not trusted from frontend input.

Business service prices default to the salon currency when a service is created. Each service can override its own currency from the service editor. The current editable service currencies are the business default plus `EUR`, `GBP`, and `USD`.

Displayed service prices use the service currency first, then the salon currency:

- Romania/RON: `120 RON`
- United Kingdom/GBP: `£120`
- USD override: `$120`

YouGo SaaS plan pricing remains separate and stays configured in `config/yougo_plans.php`.

The AI prompt receives country, currency, phone prefix, timezone, date format, and default country language. This helps the assistant use local price, phone, date, and language expectations without inventing legal or tax rules.

## Not Implemented Yet

No tax calculation logic exists yet.

No automatic phone number normalization exists yet.

No telecom/Telnyx routing logic exists yet.

Future Phone AI should use `salons.country` and `salons.phone_prefix` to choose telecom behavior, number formatting, and voice locale.
