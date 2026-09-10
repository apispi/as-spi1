---
Purpose: Long-form launch post explaining a feature, the problem, and how to use it.
Channel: blog
Placeholders: {{feature_name}}, {{author_name}}, {{publish_date}}, {{cta_url}}, {{docs_url}}
---

# Launch blog post

**Title:** Introducing {{feature_name}}
**By {{author_name}} · {{publish_date}}**

---

## The problem

[INSERT 2–3 PARAGRAPHS. Describe the failure mode a developer, SRE or platform engineer hits today. Be specific and concrete — a schema that drifted after a deploy, an MCP tool that started leaking data, a monitor that alerted too late. No hype.]

## What we built

**{{feature_name}}** [INSERT ONE-SENTENCE DEFINITION].

Here's how it works:

1. [INSERT STEP]
2. [INSERT STEP]
3. [INSERT STEP]

It sits alongside the rest of Spi, so it works with what you already have: requests over REST, MCP, A2A, gRPC, MQTT and AMQP; collections with assertions, variable extraction and data-driven runs; and the three drift signals — assertions, response contracts (schema drift) and snapshots (value regression) — that already run on a schedule.

## An example

[INSERT A WORKED EXAMPLE. Show a real request/collection/monitor and what the user sees. Use a placeholder screenshot marker: [SCREENSHOT: ...]. Keep it grounded.]

## Why it matters

- [INSERT BENEFIT]
- [INSERT BENEFIT]
- [INSERT BENEFIT]

## Try it

{{feature_name}} is live in every workspace now.

[Open Spi]({{cta_url}}) · [Read the docs]({{docs_url}})
