---
Purpose: Prompt a trialling user to convert before the trial ends; remind them what they'll keep.
Channel: email (lifecycle)
Placeholders: {{first_name}}, {{workspace_name}}, {{days_left}}, {{trial_end_date}}, {{price}}, {{upgrade_url}}, {{docs_url}}, {{support_email}}, {{unsubscribe_url}}
---

# Trial ending

**Subject line (pick one):**
- Your Spi trial ends in {{days_left}} days
- Keep your monitors running, {{first_name}}
- {{days_left}} days left on your Spi trial

**Preview text:** Upgrade to keep monitors, alerts and scheduled drift checks active.

---

Hi {{first_name}},

Your trial of Spi for **{{workspace_name}}** ends on **{{trial_end_date}}** — {{days_left}} days from now.

To keep things running after that, you'll want to upgrade. On the paid plan you keep:

- **Scheduled monitors** — uptime, latency trend, and pass/fail alerting by email and webhook or Slack.
- **Drift detection on a schedule** — assertions, response contracts (schema drift), snapshots (value regression), and MCP drift.
- **Shared workspaces** — organisations with named API keys, 2FA, and a security audit log.
- **Shareable reports and status pages.**

[Upgrade {{workspace_name}}]({{upgrade_url}}) — from {{price}}.

If you're weighing it up, the docs at {{docs_url}} cover what stays active on each plan, or reply to {{support_email}} and we'll help you scope it.

— The Spi team

---

[Unsubscribe]({{unsubscribe_url}})
