# Spi marketing & communications templates

Reusable Markdown templates for Spi (apispi.com) communications and marketing. These are **internal assets** — fill-in-the-blank templates, not copy to send as-is. Every template carries placeholders and, in longer pieces, bracketed instructions. Review and complete each one before it goes anywhere near a customer or the press.

## What Spi is (source of truth for copy)

Spi is a multi-protocol API and AI-agent testing and monitoring platform (Laravel + Vue). When writing from these templates, stay grounded in the real capabilities:

- **Tester** — send requests over REST, MCP, A2A, gRPC, MQTT, AMQP; environments with `{{variables}}` and secret masking.
- **Collections** — chain saved requests into runnable suites with assertions, variable extraction, data-driven (dataset) runs, and env parity checks.
- **Drift signals** — assertions, response contracts (schema drift) and snapshots (value regression), which also run on a schedule.
- **Monitors** — run a collection on a schedule; uptime, latency trend, alerting on pass/fail transitions (email + webhook/Slack), plus MCP drift detection.
- **Notifications & status pages** — in-app bell notifications and public, shareable uptime + latency status pages.
- **MCP suite** — gateway, flight recorder/relay, mock server, replay, policy firewall, agent explorer; conformance grading and prompt-injection security scanning of MCP tools.
- **AI Lab / Spi assistant** — author requests from plain English, explain responses, generate assertions (via a connected SCX AI key).
- **Reports** — conformance / security / run reports with shareable links, run-to-run diffs, JSON/Markdown export.
- **Security** — named API keys, two-factor auth (TOTP), active-session management, security audit log, shared team workspaces (organisations).
- **Developer API** at `/api/v1`; importers/exporters for curl, Postman, OpenAPI.

Do **not** invent pricing, headcount, funding, customer names, testimonials, or specific metrics. Where a real business fact is needed, leave the placeholder in.

## Templates

### comms/ — customer & lifecycle communications

| File | Description |
|------|-------------|
| `welcome-email.md` | New-user onboarding; points to the first useful action (send → save → monitor). |
| `email-verification.md` | Transactional email address verification with link and code. |
| `password-changed-email.md` | Security notice confirming a password change, with a path to react if it wasn't them. |
| `monitor-alert-email.md` | Alert when a monitored collection transitions to failing. |
| `monitor-recovered-email.md` | Notice that a previously failing monitor has recovered. |
| `incident-status-update.md` | Structured incident update for a public status page and/or subscribers. |
| `trial-ending-email.md` | Conversion nudge before a trial ends; what stays active on the paid plan. |
| `account-deletion-confirmation.md` | Confirms a deletion request, what happens next, and how to cancel or export. |

### campaigns/ — marketing content

| File | Description |
|------|-------------|
| `product-announcement-email.md` | Announce a significant product or launch moment to the list. |
| `feature-release-email.md` | Tell existing users about a smaller, specific improvement. |
| `monthly-newsletter.md` | Recurring digest: what shipped, what's coming, one useful tip. |
| `social-posts.md` | 4 X/Twitter posts (≤280 chars) and 2 LinkedIn posts, each self-contained. |
| `launch-blog-post.md` | Long-form launch post: problem, solution, worked example, CTA. |
| `cold-outreach-email.md` | Short, specific 1:1 outreach to a developer/SRE/platform lead. |
| `changelog-entry.md` | A single dated changelog entry (Added / Improved / Fixed). |
| `press-release.md` | Formal press release with standard structure and placeholder facts. |

## Placeholder convention

Placeholders use double braces: `{{token}}`. Every placeholder a template uses is listed in that file's front matter under `Placeholders`. Common tokens:

- **People / account:** `{{first_name}}`, `{{workspace_name}}`, `{{company_name}}`, `{{sender_name}}`, `{{author_name}}`
- **Links:** `{{cta_url}}`, `{{docs_url}}`, `{{status_page_url}}`, `{{changelog_url}}`, `{{upgrade_url}}`, `{{unsubscribe_url}}`, `{{website_url}}`
- **Monitoring:** `{{monitor_name}}`, `{{run_url}}`, `{{failure_reason}}`, `{{latency_ms}}`
- **Dates / business facts:** `{{date}}`, `{{launch_date}}`, `{{trial_end_date}}`, `{{price}}`, `{{version}}`

Two placeholder styles appear on purpose:

- `{{token}}` — a value that gets substituted (a name, a URL, a date).
- `[INSERT ...]` / `[SCREENSHOT: ...]` — a bracketed instruction to the writer to compose a sentence, a metric, or an asset. Replace these with real, verified copy; never ship the brackets.

## Brand voice

Developer-first, precise, and calm. Concise sentences, concrete benefits, no hype or exclamation-mark marketing-speak. Assume the reader is a developer, SRE, or platform engineer. British/Australian spelling. Never fabricate testimonials, metrics, or results — leave a placeholder instead.

## Before sending

1. Replace every `{{placeholder}}` and every `[INSERT ...]` bracket.
2. Confirm any capability you reference actually matches what Spi does today (see the list above).
3. Check that emails have a working unsubscribe link where required.
4. Keep transactional emails short; keep marketing emails scannable.
