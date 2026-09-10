---
Purpose: Post a structured incident update to a public status page and/or subscribers.
Channel: status page / email
Placeholders: {{incident_title}}, {{incident_status}}, {{impact_level}}, {{affected_components}}, {{started_at}}, {{update_time}}, {{summary}}, {{next_update_time}}, {{status_page_url}}
---

# Incident status update

**Subject line:** [{{impact_level}}] {{incident_title}} — {{incident_status}}

**Preview text:** {{summary}}

---

**{{incident_title}}**

- **Status:** {{incident_status}} (Investigating / Identified / Monitoring / Resolved)
- **Impact:** {{impact_level}}
- **Affected:** {{affected_components}}
- **Started:** {{started_at}}
- **This update:** {{update_time}}

{{summary}}

We'll post the next update by **{{next_update_time}}**, or sooner if the situation changes.

Live status and history: {{status_page_url}}

---

*Template note: reuse this block for each update, changing only Status, This update, the summary, and the next-update time. Keep the incident title stable across the thread so updates group together.*
