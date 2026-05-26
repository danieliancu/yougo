# Feature Knowledge Docs

Add short, user-facing feature notes here when YouGo gains a new public feature, dashboard flow, integration, or setup workflow.

Rules:
- Use one Markdown file per feature or flow.
- Keep it safe to show to customers.
- Do not include secrets, API keys, env names with values, private prompts, stack traces, customer data, or internal-only operational details.
- Explain what the feature does, where users find it, what is live today, and what is planned later.

The global YouGo Copilot reads `docs/features/*.md` automatically as part of its product knowledge. It also reads plans from `config/yougo_plans.php`, services from `config/yougo_services.php`, and navigation targets from `config/yougo_help_routes.php`.
