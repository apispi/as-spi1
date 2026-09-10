---
Purpose: Short, specific outreach to a developer/SRE/platform lead who might benefit.
Channel: email (outbound, 1:1 or lightly templated)
Placeholders: {{first_name}}, {{company_name}}, {{trigger_observation}}, {{sender_name}}, {{cta_url}}, {{unsubscribe_url}}
---

# Cold outreach

**Subject line (pick one):**
- Testing MCP and gRPC at {{company_name}}?
- One tool for your non-REST APIs
- Catching drift before your users do

**Preview text:** A quick, specific note — not a pitch deck.

---

Hi {{first_name}},

{{trigger_observation}} — which is usually where API testing starts to fragment: REST in one tool, gRPC in scripts, MCP and events tested by hand or not at all.

Spi puts them in one place. You send requests over REST, MCP, A2A, gRPC, MQTT and AMQP, save the important ones into collections with assertions, and run those on a schedule — with drift detection (schema and value regression) and pass/fail alerting to email, webhook or Slack.

If catching that kind of thing early is on your list at {{company_name}}, I can show you a five-minute setup on one of your own endpoints.

Worth a look? [See Spi]({{cta_url}}) or just reply.

{{sender_name}}

---

Not relevant? [Let me know and I won't follow up]({{unsubscribe_url}}).
