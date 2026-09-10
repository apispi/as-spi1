---
Purpose: Notify an owner that a previously failing monitor has recovered.
Channel: email (transactional / alerting)
Placeholders: {{monitor_name}}, {{workspace_name}}, {{recovered_at}}, {{downtime_duration}}, {{run_url}}, {{status_page_url}}
---

# Monitor recovered

**Subject line:** [Spi] {{monitor_name}} has recovered

**Preview text:** Back to passing after {{downtime_duration}}.

---

**{{monitor_name}}** is **passing** again.

- **Workspace:** {{workspace_name}}
- **Recovered:** {{recovered_at}}
- **Time in failing state:** {{downtime_duration}}

[View the recovering run]({{run_url}})

The latency trend and uptime history are on your status page: {{status_page_url}}

— Spi Monitors
