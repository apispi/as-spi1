---
Purpose: Onboard a new user right after sign-up; orient them to the first useful action.
Channel: email (transactional / lifecycle)
Placeholders: {{first_name}}, {{workspace_name}}, {{cta_url}}, {{docs_url}}, {{support_email}}, {{unsubscribe_url}}
---

# Welcome email

**Subject line (pick one):**
- Welcome to Spi, {{first_name}}
- Your Spi workspace is ready
- Send your first request in Spi

**Preview text:** Send a request, save it to a collection, put it on a monitor.

---

Hi {{first_name}},

Your workspace **{{workspace_name}}** is ready. Spi lets you send requests over REST, MCP, A2A, gRPC, MQTT and AMQP from one place, then turn the ones that matter into monitored, asserted suites.

A good first three minutes:

1. **Send a request.** Open the Tester, paste a URL or pick a protocol, and hit send.
2. **Save it to a collection.** Add an assertion or two so a bad response fails loudly.
3. **Put it on a monitor.** Run the collection on a schedule and get alerted on the first pass/fail transition — by email and webhook or Slack.

[Open your workspace]({{cta_url}})

If you work with MCP servers or AI agents, take a look at the MCP suite — gateway, flight recorder, mock server, replay, policy firewall, plus conformance grading and prompt-injection scanning.

The docs are at {{docs_url}}. Reply here or reach us at {{support_email}} if you get stuck.

— The Spi team

---

You're receiving this because you created a Spi account. [Unsubscribe]({{unsubscribe_url}})
