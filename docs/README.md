# Documentation

Specification and reference for **apispi.com (Spi)** — a multi-protocol API
testing and monitoring tool with a live view into what agents do over MCP
(Laravel 12 + Vue 3). These docs let an engineer or AI understand, extend, or
rebuild the project. Project overview and per-feature prose live in the
[root README](../README.md), which is kept current feature-by-feature; this
`docs/` set is the structured reference.

## Documents

| Doc | Scope |
|---|---|
| [SPECS.md](SPECS.md) | **Canonical top-level specification** — intent, feature map, data model, API surface, security invariants. |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Request lifecycle, auth model, public token routes, workspace scoping, scheduler, middleware, rate limiting, deployment. |
| [MODELS.md](MODELS.md) | Eloquent models — fillable/hidden, casts, relations, the `SharedInWorkspace` trait, business rules. |
| [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) | Every table, its purpose and key columns, and the migration order. |
| [FRONTEND.md](FRONTEND.md) | Vue SPA — router, Pinia stores, views, components, theming, build. |
| [CATALOG.md](CATALOG.md) | Catalog/Active admin sections and the connector sync that populates them. |

`SPECS.md` is authoritative; the others are focused deep-dives. If a companion
disagrees with SPECS.md, fix the companion. Where a feature has detailed prose
in the root README, SPECS links to it rather than duplicating.

## Feature map (what exists)

Beyond the base tester (REST/GraphQL/WebSocket/SOAP/Webhook/MCP/A2A/gRPC/MQTT/
AMQP), Spi has grown a testing-and-monitoring platform. The major areas, each
specified in [SPECS.md §Feature areas](SPECS.md):

- **Environments & variables** — `{{var}}` substitution, secret masking.
- **Assertions & contracts** — response checks, and schema baselines inferred
  from traffic that catch silent breaking changes.
- **Collections & runner** — ordered saved-request runs with value passing;
  `/api/v1` CI endpoint; **environment parity** diffing.
- **Monitors** — scheduled collection runs and **MCP drift** watch; uptime,
  alert channels (Slack/Discord/webhook), public **status pages**.
- **MCP gateway** — Spi as an MCP server exposing the caller's own artefacts.
- **Flight recorder** — pass-through MCP proxy that records agent↔server
  traffic, scans it live, and can **synthesize** the observed contract.
- **Webhook capture** — inbound request bin with dead-man's-switch monitoring.
- **Agent explorer** — safe-by-default autonomous MCP exploration.
- **Import/export**, **AI assist** (author/explain/assert/fix/heal),
  **shared workspaces** (organisations), **admin back-office**.

## Suggested reading order

1. **ARCHITECTURE.md** — the shape of the system and how a request flows.
2. **DATABASE-SCHEMA.md** + **MODELS.md** — the data and the rules over it.
3. **SPECS.md** — the full feature map, API surface, and invariants.
4. **FRONTEND.md** — how the SPA consumes the API.
5. **CATALOG.md** — the connector/catalog subsystem end to end.

## Repository map

| Path | Contents |
|---|---|
| `app/Http/Controllers/` | HTTP endpoints (~40 controllers) |
| `app/Http/Middleware/` | `IsAdmin`, `AuthenticateApiToken`, `ResolveEnvironmentVariables` |
| `app/Models/` | Eloquent models (see MODELS.md); `Concerns/SharedInWorkspace` |
| `app/Services/Mcp/`, `A2a/`, `Grpc/`, `Mqtt/`, `Amqp/` | Protocol clients/testers |
| `app/Services/Variables/` | `VariableResolver`, `SecretMasker` |
| `app/Services/Assertions/`, `Contracts/` | Assertion vocabulary/evaluator; schema inference + contract checking |
| `app/Services/Collections/` | `RequestExecutor`, `CollectionRunner`, `ParityChecker` |
| `app/Services/Monitors/` | `MonitorRunner`, `McpDriftDetector` |
| `app/Services/Alerts/` | `AlertDispatcher` (Slack/Discord/webhook) |
| `app/Services/Agent/` | `AgentLoopRunner`, `ServerExplorer`, `DestructiveHeuristic` |
| `app/Services/Import/`, `Export/` | cURL/OpenAPI import, snippet export |
| `app/Services/Security/` | `SsrfGuard`, `SsrfException` |
| `app/Rules/PubliclyRoutableUrl.php`, `PubliclyRoutableHost.php`, `TemplatedUrl.php` | SSRF + templated-URL validation |
| `app/Console/Commands/` | `RunDueMonitors`, `CheckWebhookSilence` |
| `app/Notifications/` | `MonitorStatusChanged`, `WebhookSilenceChanged` |
| `database/migrations/` | Schema (DATABASE-SCHEMA.md) |
| `database/seeders/` | `DatabaseSeeder` (users), `CatalogSeeder` |
| `routes/web.php`, `routes/api.php`, `routes/console.php` | SPA/API routing; scheduled commands |
| `bootstrap/app.php` | App wiring, middleware aliases, CSRF exemptions, public path |
| `config/security.php`, `config/services.php` | SSRF/admin/bot + OAuth config |
| `resources/js/` | Vue SPA (`views/`, `components/`, `store/`, `router.js`) |
| `deploy.sh`, `hk.sh` | Deploy (two-mode) and local housekeeping scripts |
| `tests/` | PHPUnit feature/unit tests |

## Conventions

- File paths are repo-relative.
- "**must** / **never**" mark invariants that protect security or correctness —
  do not regress them (consolidated in [SPECS.md §Invariants](SPECS.md)).
