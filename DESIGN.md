---
name: YouGo
description: Operational AI receptionist UI for appointment-based businesses.
colors:
  app-bg: "#f6f8fb"
  app-shell: "#ffffff"
  app-sidebar: "#0f172a"
  app-panel: "#ffffff"
  app-panel-soft: "#f8fafc"
  app-border: "#e2e8f0"
  app-border-strong: "#cbd5e1"
  app-text: "#0f172a"
  app-text-soft: "#475569"
  app-text-muted: "#64748b"
  primary: "#4f46e5"
  primary-hover: "#4338ca"
  success: "#16a34a"
  danger: "#dc2626"
  warning: "#f59e0b"
  dark-bg: "#020617"
  dark-panel: "#0f172a"
  dark-panel-soft: "#111827"
  dark-text: "#f8fafc"
  dark-text-muted: "#94a3b8"
typography:
  display:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "3.75rem"
    fontWeight: 700
    lineHeight: 1
    letterSpacing: "normal"
  headline:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "normal"
  title:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
  body:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  label:
    fontFamily: "Poppins, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "0.025em"
rounded:
  sm: "6px"
  md: "8px"
  lg: "12px"
  xl: "16px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  section: "40px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.app-shell}"
    rounded: "{rounded.md}"
    padding: "10px 16px"
    height: "40px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.app-shell}"
    rounded: "{rounded.md}"
  card:
    backgroundColor: "{colors.app-panel}"
    textColor: "{colors.app-text}"
    rounded: "{rounded.md}"
    padding: "20px"
  input:
    backgroundColor: "{colors.app-panel}"
    textColor: "{colors.app-text}"
    rounded: "{rounded.md}"
    height: "40px"
    padding: "0 12px"
---

# Design System: YouGo

## 1. Overview

**Creative North Star: "The Calm Operations Desk"**

YouGo should feel like a practical front desk for small appointment-based businesses: quiet, fast to scan, and honest about what is active today. The interface serves work first. It should help users compare plan limits, review bookings, change local test plans, and manage setup without decorative friction.

The visual system is restrained: cool neutral surfaces, one indigo action accent, compact controls, and clear table structures. This directly supports the product principle that future capabilities must be separated from active tracked features.

**Key Characteristics:**
- Dense but readable operational layouts.
- Indigo for primary action and selected state only.
- Green for included, connected, and success states.
- Red for destructive actions, warnings, and explicit discounts only.
- Tables and compact rows are preferred over decorative card grids when users compare information.

## 2. Colors

The palette is a restrained operational palette: cool slate neutrals, white panels, and a single indigo action accent.

### Primary
- **Operational Indigo**: the primary action, selected state, focus family, and high-confidence CTA color.
- **Deep Operational Indigo**: the hover state for primary actions.

### Secondary
- **Success Green**: included features, connected states, and positive confirmations.
- **Risk Red**: destructive actions, validation errors, and annual discount callouts when requested.
- **Attention Amber**: in-progress or warning states.

### Neutral
- **Workspace Mist**: the app background, used to separate the workspace from panels.
- **Panel White**: the default card, table, and form surface.
- **Soft Panel Slate**: secondary panel backgrounds, table heads, and low-emphasis sections.
- **Border Slate**: standard dividers, card borders, table rows, and input borders.
- **Ink Slate**: primary text.
- **Muted Slate**: secondary labels, helper copy, and metadata.
- **Deep Sidebar Slate**: dashboard navigation and high-contrast shell surfaces.

### Named Rules
**The One Accent Rule.** Indigo is for primary action, selection, and navigational emphasis. It is not decoration.

**The Honest State Rule.** Green means included or connected. Red means destructive, invalid, risky, or explicitly discounted. Do not use either color just to add energy.

## 3. Typography

**Display Font:** Poppins with ui-sans-serif, system-ui, sans-serif fallback  
**Body Font:** Poppins with ui-sans-serif, system-ui, sans-serif fallback  
**Label/Mono Font:** no distinct mono font is used

**Character:** Poppins gives YouGo a rounded, approachable product tone while still supporting dashboard density. The system relies on weight, case, and spacing rather than decorative type.

### Hierarchy
- **Display** (700, 3.75rem, 1 line-height): public page hero headlines only.
- **Headline** (700, 1.875rem, 1.2 line-height): page-level section headings and important marketing headings.
- **Title** (600, 1.125rem, 1.4 line-height): dashboard panel headings, card titles, and modal titles.
- **Body** (400, 0.875rem, 1.5 line-height): dashboard copy, field help, table body text, and component descriptions.
- **Label** (700, 0.75rem, 0.025em tracking): uppercased labels, nav microcopy, badges, and table headers.

### Named Rules
**The Scan First Rule.** Dashboard labels must be compact and predictable. Long explanatory text belongs under headings, not inside controls.

## 4. Elevation

The system uses a hybrid of tonal layering, borders, and subtle shadows. Most surfaces are flat at rest and use borders to define structure. Shadows appear on cards, dropdowns, modals, and public hero panels where the surface needs to lift above the page.

### Shadow Vocabulary
- **Panel Low** (`box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08)`): default card shadow via Tailwind `shadow-sm`.
- **Floating Menu** (`box-shadow: 0 10px 25px rgba(15, 23, 42, 0.18)`): dropdown menus and popovers via `shadow-2xl`.
- **Modal High** (`box-shadow: 0 20px 25px rgba(15, 23, 42, 0.18)`): modals and blocking overlays via `shadow-xl`.

### Named Rules
**The Border First Rule.** Use borders and tonal panels before shadows. Shadows are for floating UI or important lifted panels.

## 5. Components

### Buttons
- **Shape:** gently rounded rectangles (8px radius).
- **Primary:** indigo background, white text, 40px height, 16px horizontal padding.
- **Hover / Focus:** hover darkens the indigo. Focus should use the app focus ring.
- **Secondary:** bordered panel button with muted text and soft panel hover.
- **Danger:** red-tinted background and red text for destructive actions only.

### Chips
- **Style:** compact rounded rectangles (6px radius), bold 10 to 12px uppercase labels.
- **State:** selected chips use indigo or semantic tints. Status chips use stable semantic colors.

### Cards / Containers
- **Corner Style:** dashboard cards use 8px radius. Public feature panels may use 16px radius.
- **Background:** cards use `app-panel`; secondary blocks use `app-panel-soft`.
- **Shadow Strategy:** cards use low shadow sparingly, with borders carrying most structure.
- **Border:** standard card border uses the app border token.
- **Internal Padding:** dense cards use 20px. Larger public sections can use 24px.

### Inputs / Fields
- **Style:** 40px height, 8px radius, panel background, slate border, 12px horizontal padding.
- **Focus:** border shifts to indigo and uses the app focus ring.
- **Error / Disabled:** errors use red text. Disabled controls reduce opacity and disable pointer affordance.

### Navigation
- **Dashboard navigation:** dark slate sidebar, compact rows, lucide icons, active states through contrast and accent.
- **Public navigation:** white or theme-aware top nav, compact 40px controls, dropdown menus with rounded panels and shadow.
- **Mobile treatment:** public navigation collapses into a menu button and full-width CTA.

### Pricing Table
- **Structure:** table-first comparison with `Plan`, capability columns, and `Price`.
- **Capabilities:** green check for included, long dash for unavailable, small limit text below included checks.
- **Billing cycle:** segmented control above the table. Annual pricing shows the reduced annual total plus a red percentage discount.

## 6. Do's and Don'ts

### Do:
- **Do** keep dashboard screens dense but readable, using tables and compact controls for comparison tasks.
- **Do** keep pricing limits visible where users make plan decisions.
- **Do** distinguish active capabilities from future positioning. WhatsApp, Phone AI, Telnyx, Stripe, and payments must not look live before implementation.
- **Do** use indigo for primary actions and selected states.
- **Do** use green only for included, connected, or successful states.
- **Do** use borders, dividers, and tonal surfaces before adding shadows.

### Don't:
- **Don't** use generic AI SaaS pages with oversized gradients and vague claims.
- **Don't** build overly decorative dashboards that slow down scanning.
- **Don't** create pricing tables that hide real limits or overpromise unavailable channels.
- **Don't** replace compact comparison tables with heavy card grids when users need to compare plans.
- **Don't** use complex custom controls that behave differently from standard web UI.
- **Don't** imply unlimited usage unless the backend config and enforcement support it.
