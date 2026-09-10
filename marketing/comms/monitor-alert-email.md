---
Purpose: Alert an owner when a monitored collection transitions to failing.
Channel: email (transactional / alerting)
Placeholders: {{monitor_name}}, {{workspace_name}}, {{failed_at}}, {{failure_reason}}, {{failed_check}}, {{latency_ms}}, {{run_url}}, {{status_page_url}}, {{mute_url}}
---

# Monitor alert (failing)

**Subject line (pick one):**
- [Spi] {{monitor_name}} is failing
- Alert: {{monitor_name}} — {{failure_reason}}

**Preview text:** First failed run detected at {{failed_at}}.

---

**{{monitor_name}}** just transitioned to **failing**.

- **Workspace:** {{workspace_name}}
- **Detected:** {{failed_at}}
- **Reason:** {{failure_reason}}
- **Failed check:** {{failed_check}}
- **Latency:** {{latency_ms}} ms

[View the run]({{run_url}})

The run report shows the failing request, the assertion, contract, or snapshot that tripped, and a diff against the last passing run. You'll get a recovery notice when the monitor passes again.

Public status: {{status_page_url}}
[Mute this monitor]({{mute_url}})

— Spi Monitors
