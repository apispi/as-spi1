# apispi.com

A multi-protocol API testing tool built with Laravel 12 and Vue 3. Send and
inspect REST, MCP (Model Context Protocol), and A2A (Agent-to-Agent) requests
from the browser, save and replay them, and review request history — with an
admin panel for user management.

## Documentation

Detailed specs live in [`docs/`](docs/):

- [SPECS.md](docs/SPECS.md) — canonical top-level specification (rebuild from scratch)
- [ARCHITECTURE.md](docs/ARCHITECTURE.md) — request lifecycle, auth model, routing, deployment
- [MODELS.md](docs/MODELS.md) — Eloquent models, casts, relations, rules
- [DATABASE-SCHEMA.md](docs/DATABASE-SCHEMA.md) — tables, columns, indexes, migrations
- [FRONTEND.md](docs/FRONTEND.md) — Vue SPA: router, stores, views, components
- [CATALOG.md](docs/CATALOG.md) — Catalog/Active sections and connector sync

## Stack

- **Backend:** Laravel 12 (PHP 8.2+), session-cookie auth
- **Frontend:** Vue 3 + Vue Router + Pinia, built with Vite
- **Public web root:** `public_html/` (SiteGround layout, set in `bootstrap/app.php`)

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev        # or: npm run build
```

`composer dev` runs the server, queue, logs, and Vite together.

## Environment variables

Beyond the standard Laravel keys:

| Variable | Purpose |
| --- | --- |
| `ADMIN_PASSWORD` | Password for the seeded `admin@apispi.com` account. If unset, the seeder generates a random one and prints it **once**. |
| `BOT_PASSWORD` | Shared password for the two seeded test users, `bot89@apispi.com` (User 1) and `bot97@apispi.com` (User 2). Same unset behaviour as above. |
| `SSRF_RESOLVE_DNS` | When `true` (default), the SSRF guard resolves hostnames and blocks any that map to private/reserved IPs. Set `false` only in test environments. |

## Protocols

The authenticated dashboard and the public homepage both test the full protocol
set: REST/GraphQL/WebSocket/SOAP/Webhook/MCP/A2A/gRPC/MQTT/AMQP. gRPC, MQTT, and
AMQP connect to real backends, so they run only through the authenticated test
endpoints (a logged-out homepage visitor is prompted to sign in).

- **MCP** — `App\Services\Mcp\McpClient`, Streamable HTTP transport (JSON + SSE),
  session handling, and a "Discover Tools" flow that reads `tools/list` and
  auto-fills a `tools/call` template from each tool's `inputSchema`.
- **A2A** — `App\Services\A2a\A2aClient`, JSON-RPC with agent-card discovery
  (`.well-known/agent-card.json`) and a `message/send` template filler.
- **gRPC** — `App\Services\Grpc\GrpcClient`, unary calls over HTTP/2 with a
  pure-PHP protobuf codec (`ProtobufCodec`). Request messages are described as
  explicit field lists; responses are decoded generically and the gRPC status
  trailer is surfaced. Streaming is out of scope.
- **MQTT** — `App\Services\Mqtt\MqttTester` (php-mqtt/client). Publish and/or
  subscribe with a bounded timeout and message cap.
- **AMQP** — `App\Services\Amqp\AmqpTester` (php-amqplib). Publish to an
  exchange/routing-key and/or pull messages from a queue via `basic_get`.

A CLI client is also available: `php artisan mcp:test <url>` (interactive REPL
or `--method=` for one-shot calls).

## Environments and variables

Environments hold reusable values so one saved request can run against staging
and production. Reference them as `{{name}}` anywhere in a request — URL,
headers, body, MQTT topics, gRPC metadata:

```json
{ "environment": "Staging", "url": "https://{{base_url}}/users" }
```

`App\Http\Middleware\ResolveEnvironmentVariables` substitutes them **before**
validation, so every URL rule — including the SSRF guard — sees the resolved
target and a variable cannot smuggle an internal host past those checks.
Unknown placeholders are left in place and reported back under `environment.
unresolved`. Variables marked **secret** are masked (`••••••`) in request
history and in the echoed request, and their values are never returned to the
browser.

## Assertions

A saved request can carry assertions that validate its response:

```json
{ "path": "data.items.0.id", "operator": "is_type", "expected": "number" }
```

`path` is `status`, `time_ms`, `header.<name>`, or a dot path into the JSON
body (`$.data.items[0].id` and `data.items.0.id` are equivalent). The operator
vocabulary is **closed** — see `App\Services\Assertions\Assertion` — so
anything stored is guaranteed evaluable, and the AI generator
(`POST /api/ai/assert`) is constrained to the same list. `AssertionsPanel.vue`
mirrors it, and `AssertionVocabularyTest` fails if the two drift apart.

`App\Services\Assertions\AssertionEvaluator` never throws on bad input: an
assertion that cannot be evaluated fails with a reason, so one malformed row
does not take down the suite.

- `POST /api/assertions/evaluate` — evaluate assertions against a response
- `PUT /api/saved-requests/{id}/assertions` — attach assertions to a request

## Navigation

The workspace sections are Home, Tester, Collections, AI Lab, Monitors,
Reports, and Spi. **Collections** (`/collections`) is where saved requests
live: a tab each for saved requests, collections, and request history. Opening
a saved request hands it to the tester through the requests store, so the
tester itself stays a single-purpose two-pane view with no sidebar.

The **admin area** is separate: it is reached from the profile menu, and
inside it the sidebar shows admin sections only — the workspace nav is
replaced entirely, with a "Leave admin" link back to the dashboard. It has six sections — **Overview**
(`/admin`: failing monitors, stats, connectors, recent admin actions),
**Users** (`/admin/users`, with a detail page per user at
`/admin/users/{id}`), **Organisations**, **Monitoring** (every monitor in the
workspace, failing first), **Catalog**, and **Active**. Admin pages share one
design contract, `resources/js/views/admin-shared.css` — every page opens
with stat tiles answering "is anything wrong?", then the list answering
"where?".

**User lifecycle.** An admin can create accounts directly (created verified,
so the person can sign in immediately) and remove them two ways:

- **Deactivate (soft delete)** — reversible. Soft-deleted users are excluded
  from every query, the auth provider included, so the account is locked out
  while everything it owns is kept. Restoring brings it back intact.
- **Delete forever (hard delete)** — permanent, and cascades to saved
  requests, history, environments, collections, monitors, alert channels, and
  reports. The UI requires typing the user's email to confirm.

Self-service deletion (`DELETE /api/user/account`) stays a **hard** delete:
someone deleting their own account is asking for erasure, and the Privacy
Notice promises it.

The audit log deliberately outlives both: `target_user_id` is a snapshot
rather than a foreign key, and `admin_id` nulls out while `admin_email` keeps
the identity — so hard-deleting an admin no longer erases every action they
took.

Organisations are a **full shared workspace**: everyone in an organisation
sees and uses one shared pool of resources — saved requests, environments,
collections, monitors, alert channels, webhook endpoints, MCP recorders,
status pages, and reports. A user with **no** organisation is a workspace of
one, so solo accounts are unchanged.

`user_id` still records the creator (shown as an owner badge on shared items
so the pool is not anonymous); scoping is what changes. The
`App\Models\Concerns\SharedInWorkspace` trait adds `inWorkspaceOf($user)`,
which every resource controller uses in place of per-user scoping — read, use,
edit, and delete all operate over `User::workspaceUserIds()`. Names that are
selected by name (environments, collections, monitors…) are unique per
workspace, and cross-references (a monitor's collection, a collection step's
saved request, a status page's monitors) resolve across the workspace too.

**Personal, not shared:** request history, API keys, SCX keys, and profile —
these are individual activity and credentials, not workspace artefacts.
Deleting an organisation unassigns its members (their resources revert to
private) rather than deleting anything.

## Collections

A collection is an ordered group of saved requests, run start to finish
against one environment with each step's assertions checked:

```bash
curl -X POST https://spi.apispi.com/api/v1/collections/12/run \
  -H "Authorization: Bearer spi_your_key" \
  -d '{"environment":"Staging"}'
```

The status code is the verdict — **200** when every step passed, **422** when
any failed — so CI can gate on it without parsing the body.

`App\Services\Collections\CollectionRunner` threads variables between steps:
the pool starts as the environment's variables, and each step can `extract`
values from its response (`{"name":"token","path":"data.token"}`) that later
steps reference as `{{token}}`. Resolution happens per step, immediately
before sending, which is what makes that threading work.

`App\Services\Collections\RequestExecutor` applies the same SSRF validation
and IP pinning as the interactive testers, for every protocol — gRPC, MQTT,
and AMQP included. Their connection details (host, topic, credentials) live in
the saved request's `params` and are resolved from the environment before the
step runs, so a broker password can be a secret `{{variable}}` instead of
plaintext on the row.

Those protocols have no HTTP status, so the whole tester result becomes the
assertion body — exactly as the tester UI normalises it, so an assertion means
the same thing interactively and in a run. Assert on `grpc_status`,
`published`, `message_count`, or `messages.0.message` rather than `status`.

Runs stop at the first failure unless the collection sets
`continue_on_failure`; remaining steps are reported as skipped. Every run is
persisted as an `InspectionReport` of type `collection_run`, so it is
shareable and diffable like any other report.

## Import and export

Paste a `curl` command — including "Copy as cURL" from browser devtools — and
it fills the tester without saving anything. `App\Services\Import\CurlImporter`
tokenises the command by hand rather than per-flag regexes, because quoting is
the actual problem: JSON bodies arrive single-quoted and full of double quotes,
with line continuations throughout. It follows curl's own semantics (a body
without `-X` implies POST, `-u` becomes an `Authorization` header, repeated
`-d` flags join with `&`).

`App\Services\Import\OpenApiImporter` turns an OpenAPI 3 document (YAML or
JSON) into saved requests, optionally a collection and an environment. Server
URLs become `{{base_url}}` and path/required-query parameters become
`{{placeholders}}` rather than being baked in — so an imported collection can
be pointed at staging or production, which is the reason to import into Spi
rather than just read the spec. Assertions are derived only from what the spec
actually promises (documented status code, documented top-level type);
inventing field-level checks from a schema produces assertions that fail
against a healthy API.

Any saved request exports as a runnable snippet — cURL, JS `fetch`, Python
`requests`, or raw HTTP. `{{variables}}` are left in place: the snippet is for
a human to paste, and substituting a secret into copyable text is the opposite
of what the secret flag is for.

- `POST /api/import/curl` — parse a curl command (preview only)
- `POST /api/import/openapi` — create saved requests from a spec
- `GET /api/saved-requests/{id}/export?format=` — snippet for a saved request
- `POST /api/export` — snippet for an unsaved draft

## Agent Explorer (autonomous MCP exploration)

`POST /api/explore` points an autonomous agent at any MCP server with a goal:
`App\Services\Agent\ServerExplorer` (a subclass of the connector agent loop)
lets SCX pursue the goal by calling the server's tools, and reports the path it
took, the tool surface it was exposed to, and — the point — every destructive
tool it reached for.

**Safe by default.** Safe mode (on unless explicitly disabled) refuses tools
whose names look side-effecting (`DestructiveHeuristic` — delete/update/send/
pay/deploy…), so aiming an autonomous model at a live server does not risk
mutating it. A refused call is a *finding*, not an execution: "the agent tried
to call `delete_user` to meet the goal" is exactly what you want to learn. The
discovered tools are also run through the injection scanner, so an exploration
doubles as a security scan of what the agent saw. Runs on the caller's SCX key;
persists as an `exploration` report. Launched from the Explorer view.

## MCP flight recorder

A pass-through proxy at `/mcp-proxy/{token}`: point an agent at the recorder
URL instead of a real MCP server, and every call it makes is forwarded
upstream and recorded — method, arguments, response, status, timing. Everything
else in Spi tests a server directly; this records what an agent actually *did*
with one.

Faithful relay: the JSON-RPC body and the MCP session headers travel unchanged
both ways, and the agent's `Authorization` is forwarded so upstream auth
works — but it is never written to the recording. The upstream is re-pinned
against the SSRF guard on every relay. Each JSON response passes through the
same injection scanner the security page uses, live, so a tool description or
result that tries to hijack the agent flags the exchange on real traffic —
filterable to flagged-only in the timeline. Managed under Recorder; token-gated
and revocable, with per-recorder retention.

## MCP mock server (service virtualization)

Spi **serves** a fake MCP server at `/mcp-mock/{token}` — point an agent at it
while the real server does not exist yet, is rate-limited, or costs money to
call. It answers the handshake, `tools/list`, and `tools/call` from a stored
definition (`McpMockServeController`, structured like the real gateway).

Define tools by hand, or **seed one from a flight recorder**: `POST
/api/mcp-mocks/from-recorder/{proxyId}` turns each tool the recorder observed
into a mock tool — its observed input schema and a real sample response to
replay. The loop closes: record production MCP traffic once, serve it back as a
runnable stand-in. Token-gated, workspace-shared, managed from the Mocks view.

## Synthesize a contract from traffic

`GET /api/mcp-proxies/{id}/synthesize` reverse-engineers an MCP server's real
contract from recorded flight-recorder traffic. For each tool an agent actually
called, `App\Services\Mcp\McpTrafficSynthesizer` infers the input schema from
the arguments that were really sent and the output schema from the responses
that really came back, then sets both beside what `tools/list` declared. The
novel output is the divergence: "the server declares `search` takes `{query}`,
but agents send `{query, limit}` and get back `{results[], nextCursor}`" — and
it surfaces tools that were **called but never declared**. No schema authoring;
it is learned from what happened. Launched from Recorder → Synthesize contract.

## MCP gateway (Spi as an MCP server)

`POST /api/gateway/tools` is a real MCP endpoint (Streamable HTTP, stateless),
authenticated with a personal API key. Its tools are the caller's own
artefacts: `list_collections`, `run_collection`, `get_monitor_status`,
`evaluate_assertions`, and a guarded `http_request`. Any MCP client — an
agent included — can run your test suites and read your monitors; runs
persist as reports exactly like UI runs. This is the endpoint the seeded
"Spi Gateway" connector advertises.

## Webhook capture

Each endpoint gets a URL at `/hook/{token}` — the token is the credential.
Whatever arrives (any method) is captured: headers (with
Authorization/Cookie redacted at capture), query, body (truncated at 64KB),
and IP, with per-endpoint retention. Captures replay into the tester.

**Triggered runs.** An endpoint can fire a **collection run** on every capture
(`trigger_collection_id`) — event-driven testing: "when the provider sends this
callback, run my verification suite." The run is queued (off the request path,
so the webhook 200s immediately), executes as the endpoint's owner, and
top-level JSON fields of the payload are exposed to it as
`{{webhook_<field>}}` so the suite can target the exact record the callback
named. Needs a queue worker to process, like alerts need SMTP.

With an expectation set ("a request at least every N minutes") the endpoint
becomes a **dead-man's switch**: a scheduler tick flags silence and alerts on
the transition — email plus every enabled alert channel — and again on
recovery. This watches for *absence*: dead crons, stuck queues, revoked
callbacks. An endpoint that never received anything can still go silent.

## Monitors

A monitor runs a collection on a schedule and alerts on status changes:

```bash
php artisan monitors:run           # runs every monitor whose interval elapsed
php artisan monitors:run --id=3    # run one now, ignoring its schedule
```

`App\Services\Monitors\MonitorRunner` records each run as a compact history
point (for uptime and latency) plus a full `InspectionReport`, and emails the
owner **only on a transition** — passing to failing, or back. Alerting on
every failing run is what gets monitoring muted, and a muted monitor is not a
monitor. The first run establishes a baseline and never alerts.

A run that throws is still recorded as a failing result: a monitor that goes
silent is worse than one reporting an error. Intervals are restricted to
`Monitor::INTERVALS` so a monitor cannot hammer a target.

### MCP drift watch

A monitor with `type: mcp_drift` watches an MCP endpoint instead of running a
collection: each run snapshots `tools/list` (canonical-JSON hashes, so key
order is not drift) and alerts when a tool is added, removed, or changes its
schema — or its **description**, which counts deliberately: agents read
descriptions as instructions, so a rewritten description is a changed
contract and the classic prompt-injection vector. Drift alerts once, then the
new shape becomes the baseline. Snapshots persist as `mcp_drift` reports.

### Status pages

A monitor set can be published as a public status page at `/status/{token}` —
overall state, per-monitor uptime strips, and timing, self-refreshing every
minute. The payload is deliberately sparse: monitor names, pass/fail history,
and timing — never target URLs, step detail, or the owner's identity, and
only monitors the owner explicitly opted in. Publishing an MCP drift monitor
gives the novel case: a public page attesting that an MCP server's tool
contract is stable. Managed from Monitors → Status pages (capped at 5 per
user); disabling a page 404s its link immediately.

### Alert channels

Besides email, a monitor can post to Slack, Discord, or any endpoint. Channels
belong to a user and are shared across their monitors, so a webhook URL is
entered once:

- `GET/POST/PUT/DELETE /api/alert-channels`
- `POST /api/alert-channels/{id}/test` — send a sample alert

Webhook alerts need **no mail server**, so they work with SMTP unconfigured.
Slack and Discord get a sentence; a generic webhook gets a structured
`monitor.status_changed` event. Delivery follows the same transition rule as
email — on change only, never every failing run.

The URL is a credential (anyone holding a Slack webhook URL can post to that
channel), so it is stored whole but never returned to the browser; the API
returns a `url_preview` instead. Because Spi POSTs to it server-side, the URL
is SSRF-validated on write and the address is pinned again at delivery.
Delivery never throws: a broken channel records its error and the run stands.

### Server setup (required)

The scheduler is registered in [`routes/console.php`](routes/console.php), but
Laravel's scheduler needs one cron entry on the server. In SiteGround's cPanel
→ Cron Jobs, add:

```
* * * * * cd ~/www/spi.apispi.com && php artisan schedule:run >> /dev/null 2>&1
```

Alerts also need real mail credentials in the production `.env`
(`MAIL_MAILER=smtp` and the rest). With `MAIL_MAILER=log` they are written to
the log file instead of sent.

## Contract-driven fuzzing

`POST /api/saved-requests/{id}/fuzz` mutates a REST request's JSON body into
adversarial variants targeted at its real fields — because the field types are
known, the mutations are pointed: wrong types, dropped required fields, boundary
numbers, oversized strings, injection payloads, and structural abuse
(`App\Services\Fuzz\FuzzGenerator`). Each variant is sent through the shared
`RequestExecutor` (same SSRF pinning as any outbound call) and classified:

- **server_error** (5xx) — the endpoint crashed on bad input (a finding);
- **accepted_invalid** — 2xx on a genuine type/shape violation the endpoint
  should have rejected (a finding);
- **rejected** (4xx) — handled gracefully.

Injection and oversized inputs are crash-probes, not "should-reject" cases, so
they only surface as findings if they cause a 5xx — keeping results low-noise.
`{{variables}}` resolve against an environment and the resolved URL is
SSRF-checked. 200 clean / 422 with findings; persists a `fuzz` report. Run from
Collections → a saved request's **Fuzz** button.

## Response contracts (schema drift)

A contract is a JSON-Schema baseline **inferred from a known-good response** —
no schema authoring. `App\Services\Contracts\SchemaInferrer` walks a response
into types, object properties, which fields were always present, and array
element shape; `ContractChecker` validates a later response against it.

Attach one to a saved request (tester → Contract → Capture, or
`PUT /api/saved-requests/{id}/contract` with a good response). Every collection
run then checks the live response against it: a **removed required field or a
type change is breaking and fails the step** — catching silent breaks no
assertion was written for, even at a green 200 — while a new field is reported
as additive and passes. Drift shows per step in run results and, since runs are
monitored, alerts through the existing machinery. This is contract testing with
zero assertions written.

## Environment parity

`POST /api/collections/{id}/parity` runs a collection against **two**
environments and diffs the responses — "does staging behave like production?".
`App\Services\Collections\ParityChecker` compares by **shape**, reusing the
contract engine: value differences (ids, timestamps) are expected between
environments and ignored, while a field one side returns and the other does
not, a type that differs, or a status mismatch is flagged as divergence. Runs
persist as `parity` reports; secret values are masked and raw bodies are
diffed transiently, never stored. Launched from Collections → Compare envs.

## Self-healing assertions

When assertions fail because the API legitimately changed, `POST /api/ai/heal`
asks SCX for the updated set that preserves each assertion's intent —
structural checks keep their shape, expected values update, and assertions
whose fields vanished are dropped with a stated reason, never silently. The
tester's Heal button applies the proposal for review with one-click Undo;
nothing is saved until the user saves. Runs on the caller's own SCX key.

## Spi (AI assistant)

`Spi` is the in-app assistant at `/chat`, backed by
`App\Http\Controllers\ScxChatController`. It runs on the user's own SCX API key
(added in Profile) and carries a product-aware system prompt so it can point
users at real features and pages rather than inventing them.

## Key API endpoints

All under `/api`, session-authenticated unless noted. State-changing routes
require the CSRF token (axios sends it automatically from the `XSRF-TOKEN`
cookie).

- `POST /proxy` — REST proxy (open to guests; rate-limited)
- `POST /mcp/test`, `POST /a2a/test` — MCP/A2A testers (auth)
- `POST /grpc/test`, `POST /mqtt/test`, `POST /amqp/test` — gRPC/MQTT/AMQP
  testers (auth; also under `/api/v1` with a personal API key)
- `GET/POST/PUT/DELETE /environments` — environments and their variables (auth)
- `GET/POST/DELETE /saved-requests` — saved requests (free plan capped at 10)
- `POST /assertions/evaluate`, `PUT /saved-requests/{id}/assertions` — response
  assertions (auth)
- `GET/POST/PUT/DELETE /collections`, `POST /collections/{id}/run` — collections
  and runs (auth; run also under `/api/v1` with a personal API key)
- `GET/POST/PUT/DELETE /monitors`, `GET /monitors/{id}`, `POST /monitors/{id}/run`
  — scheduled monitors and their history (auth)
- `GET/POST/PUT/DELETE /alert-channels`, `POST /alert-channels/{id}/test` —
  Slack/Discord/webhook alert destinations (auth)
- `GET/DELETE /history` — request history (200/user retention)
- `PUT /user/profile`, `/user/password`, `GET /user/stats`, `/user/activity`,
  `DELETE /user/account` — profile management
- `GET /admin/{users,stats,actions}`, user promote/delete — admin only

## Security notes

- **SSRF:** `App\Rules\PubliclyRoutableUrl` blocks loopback/private/reserved
  hosts on all outbound endpoints, resolving DNS to catch hostnames pointing
  inward. Outbound clients do **not** follow redirects, to prevent a validated
  URL from bouncing to an internal address. DNS rebinding (a host that resolves
  public at validation and private at connection time) is closed for http(s)
  traffic by `App\Services\Security\SsrfGuard`, which resolves the host, checks
  every address, and pins the validated IP into the connection via
  `CURLOPT_RESOLVE` so cURL cannot re-resolve — applied by the proxy, MCP, A2A,
  and gRPC clients (gRPC rides on cURL too). The socket testers pin differently
  because they cannot use `CURLOPT_RESOLVE`: `SsrfGuard::validatedAddress()`
  returns a checked IP, MQTT and AMQP connect to **that address** while
  verifying TLS against the original **hostname** via `peer_name`, so the
  certificate is still checked against the name the user asked for. php-mqtt
  builds its own stream context with no `peer_name` hook, so
  `App\Services\Mqtt\PinnedMqttClient` overrides socket creation to supply
  one.
- **Noncanonical hosts:** host checks canonicalise before comparing
  (`ChecksHostRoutability::canonicalHost`) — lowercase, strip brackets, drop
  the trailing DNS root dot. Without that, `http://127.0.0.1./` matched
  neither the blocklist nor `FILTER_VALIDATE_IP` and was allowed whenever
  `SSRF_RESOLVE_DNS=false`. `SsrfGuard` still pins `CURLOPT_RESOLVE` using the
  host **as cURL reads it** from the URL, since a canonicalised key would
  silently fail to match and let cURL resolve the name again.
- **Email validation:** endpoints that store or mail an address validate with
  `email:filter`, not the default `email` rule, which accepts a quoted local
  part containing CRLF (`"a\r\nb"@example.com`) — GHSA-5vg9-5847-vvmq.
- **Rate limiting:** guest proxy 20/min per IP (120 authed), MCP/A2A 60/min per
  user, login/register 10/min per IP.
- **Data exposure:** the user's password hash and SCX API key are hidden from
  serialization; request history never stores request headers (credentials).

### Operational TODO

If the seeder ever ran in production with the old default, rotate the
`admin@apispi.com` password — either log in and change it, or re-run
`php artisan db:seed` with `ADMIN_PASSWORD` set in the production `.env`.

## Housekeeping (`hk.sh`)

A local menu-driven helper for routine tasks, alongside
[`deploy.sh`](deploy.sh). Run it bare for the menu, or jump straight to an
option:

```bash
./hk.sh          # menu
./hk.sh 05       # preflight: build + test + audit
./hk.sh 31 "msg" # deploy with a commit message
```

| | |
| --- | --- |
| `00`–`05` | status, test, build, audit, lint, **preflight** |
| `10`–`13` | migrate, seed, fresh database, reset local admin password |
| `20`–`22` | serve, run due monitors, clear caches |
| `30`–`31` | push (gated on build + tests), deploy |
| `90`–`99` | clean logs, script history, **doctor** |

`05 preflight` is the gate this project asks for before pushing — assets built,
tests green, no advisories — and exits non-zero when any of them fails, so it
also works in a hook or CI. `99 doctor` reports tool versions, the current
database, and calls out the two things that silently do nothing when
unconfigured: mail going to the log (so monitor email alerts never send) and an
unset `ADMIN_PASSWORD`.

Destructive options (`12` fresh database, `90` clean) confirm first. The script
runs only against your local machine; the server side stays with `deploy.sh`.
Its history is written to `log/housekeeping.log`, which is gitignored.

## Deployment

`./deploy.sh user@host` builds assets, commits them, pushes, and runs the
server-side steps (pull, conditional `composer install`, migrate, cache clear)
over SSH. See the header of [`deploy.sh`](deploy.sh) for details. `REMOTE_PATH`
defaults to `~/www/apispi.com`.

## Tests

```bash
php artisan test
```

Covers the MCP/A2A clients, all outbound endpoints (including SSRF and rate
limiting), auth flows, saved-request cap, request history, admin panel, and the
user profile endpoints.
