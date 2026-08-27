# Models

Eloquent models in `app/Models`. For raw table/column definitions see
[DATABASE-SCHEMA.md](DATABASE-SCHEMA.md); for the catalog specifics see
[CATALOG.md](CATALOG.md).

> **Primary source files:** `app/Models/{User,SavedRequest,RequestHistory,
> AdminAction,CatalogItem}.php`.

---

## User

Represents both password accounts and OAuth/email-first accounts, so `name`
and `password` are **nullable**.

**Critical rule:** configuration uses plain properties, **not** PHP attributes.
`#[Fillable]` / `#[Hidden]` do nothing in Laravel 12 and previously broke
registration (mass-assignment 500) and leaked the password hash + SCX key. Use:

```php
protected $fillable = ['organisation_id','name','email','password','is_admin','scx_api_key','google_id','avatar'];
protected $hidden   = ['password','remember_token','scx_api_key','api_token','registration_token'];
```

**Casts:** `password` → `hashed`, `is_admin` → `boolean`, `scx_api_key` →
`encrypted`, `preferences` → `array`, `api_token_created_at` /
`registration_token_expires_at` → `datetime`.

**Constants:** `API_KEY_PREFIX = 'spi_'`.

**Methods:**
- `isAdmin(): bool`
- `generateApiKey(): string` — creates `spi_`+40 random chars, stores
  `hash('sha256', $plain)` in `api_token`, sets `api_token_last_four` and
  `api_token_created_at`, returns the **plaintext once**.
- `static hashApiKey(string): string` — `sha256` (fast hash is fine; the key is
  high-entropy).
- `static findByApiKey(string): ?User` — lookup by hash.

**Soft deletes:** `use SoftDeletes`. A soft-deleted user is excluded from every
query — including the auth provider — so an admin "deactivate" locks the account
out; `restore()` brings it back. Self-service account deletion force-deletes
(the Privacy Notice promises erasure).

**Relations:** `organisation()`, `savedRequests()`, `requestHistories()`,
`environments()`, `collections()`, `monitors()`, `alertChannels()`,
`webhookEndpoints()`, `statusPages()`, `mcpProxies()`.

**Workspace:** `workspaceUserIds(): array` — every user id in the same
organisation, or `[self]` when unaffiliated. Drives `SharedInWorkspace` scoping
(see below).

**Secrets never serialize** — password hash, `scx_api_key` (encrypted at rest,
decrypted on access, so it *must* be hidden), `api_token`, `registration_token`.

---

## SavedRequest

A user's saved request config. `protocol` in `rest|mcp|a2a|grpc|mqtt|amqp`. Uses `SharedInWorkspace`.

- **Fillable:** user_id, name, protocol, method, url, headers, body, params, assertions, contract.
- **Casts:** headers, params, assertions, contract → array.
- `assertions` = `[{path,operator,expected,description}]`; `contract` = an inferred response-schema baseline (see SPECS Contracts).
- For MCP/A2A: `method` holds the JSON-RPC method name and `params` the
  arguments; `body` is used for REST.
- **Relation:** `user()`.
- **Business rule (enforced in controller, not model):** non-admin users are
  capped at `SavedRequestController::FREE_PLAN_LIMIT = 60`; admins exempt. Creation is per-user; visibility is per-workspace.

---

## RequestHistory

An automatically recorded outbound request (proxy/MCP/A2A) for an authenticated
user.

- **Constant:** `RETENTION_PER_USER = 200`.
- **Fillable:** user_id, protocol, method, url, params, body, status, time_ms.
- **Casts:** params → array.
- **`status`** is nullable — **null means the call failed** (no HTTP status).
- **Never stores request headers** (they carry credentials); `record()` also **masks resolved secret values** in url/body/params via `SecretMasker`.
- **Personal, not workspace-shared** — history is individual activity.
- **`static record(int $userId, array $attrs): void`** — inserts the row, then
  deletes rows older than the newest `RETENTION_PER_USER` for that user (keeps
  history bounded).
- **Relation:** `user()`.

---

## AdminAction (audit log)

Immutable record of privileged admin operations.

- **Fillable:** admin_id, **admin_email**, action, target_user_id, target_email, details.
- **Casts:** details → array.
- **Relation:** `admin()` → belongsTo User (`admin_id`, **nullable**).
- `action` values include: `create_user`, `promote_admin`, `demote_admin`, `delete_user` (soft), `force_delete_user` (hard), `restore_user`, `assign_organisation`, `unassign_organisation`.
- **Design:** `target_user_id` is **not** a foreign key and `target_email` is a
  snapshot, so the entry **survives deletion of the target user**; `admin_id` is
  nullable with `nullOnDelete` and `admin_email` a snapshot, so it also survives
  deletion of the acting admin. Hard-delete entries record the counts destroyed.

---

## CatalogItem

One model backs all catalog entity types (`agent|skill|connector|tool|prompt|resource`) and both the Catalog (all) and Active (`is_active=true`) admin
sections. Full behaviour in [CATALOG.md](CATALOG.md).

- **Constant:** `TYPES = ['agent','skill','connector','tool','prompt','resource']`.
- **Fillable:** type, name, slug, description, version, provider, metadata,
  is_active.
- **Casts:** metadata → array, is_active → boolean.
- **Scopes:** `ofType(string $type)`, `active()`.
- Unique `(type, slug)`. Activation is **workspace-wide** (a boolean flag), not
  per-user.
- `metadata` holds type-specific data: connectors store
  `{endpoint, protocol, auth_header?, last_synced_at?}`; imported tools store
  `{inputSchema, connector_slug, endpoint, protocol}` (**never** the connector
  `auth_header`).

---

## SharedInWorkspace (trait)

`app/Models/Concerns/SharedInWorkspace`. Applied to every shareable resource
model. Adds:
- `scopeInWorkspaceOf($query, User $user)` — `whereIn('user_id',
  $user->workspaceUserIds())`. Controllers use `Model::inWorkspaceOf($user)`
  for list/find/update/delete instead of `$user->relation()`. Creation stays
  owner-attributed; per-user caps stay per user.
- `owner()` — belongsTo the creating user, so shared lists can show whose a
  resource is.

Models using it: `Environment`, `SavedRequest`, `Collection`, `Monitor`,
`AlertChannel`, `WebhookEndpoint`, `StatusPage`, `McpProxy`,
`InspectionReport`.

---

## Organisation

A customer workspace. `name`, `slug` (unique via `uniqueSlug()`), `description`,
`is_active`. `users()` hasMany. Membership is a **full shared workspace** (see
SPECS), not merely administrative. Deleting an organisation unassigns members
(`nullOnDelete`) rather than deleting them.

## Environment

`user_id`, `name`, `variables` (`[{key,value,secret}]`), `is_default`. Uses
`SharedInWorkspace`. `map()` → `key=>value`; `secretValues()` → secret values
for masking; `toClientArray()` → never returns secret values, only `has_value`.
Constants `MAX_PER_USER=20`, `MAX_VARIABLES=100`.

## Collection / CollectionStep

`Collection`: `user_id`, `name`, `description`, `continue_on_failure`; `steps()`
ordered by position; `SharedInWorkspace`. Caps `MAX_PER_USER=25`, `MAX_STEPS=50`.
`CollectionStep`: `collection_id`, `saved_request_id`, `position`, `extract`
(`[{name,path}]`).

## Monitor / MonitorResult

`Monitor`: `SharedInWorkspace`. `type` (`collection|mcp_drift`), `collection_id`
(nullable), `environment_id`, `target_url` (drift), `interval_minutes`,
`is_enabled`, `alerts_enabled`, `last_status`, `consecutive_failures`.
In-memory `$attributes` mirror column defaults (else a fresh model reads null and
is never due). `isDue()`/`scopeEnabled()` decide scheduling **in PHP** (dialect
portability). `uptime()` from results. `alertChannels()` belongsToMany.
`INTERVALS` fixed set. `MonitorResult`: compact history point; `driftSnapshot()`
reads the stored tools/list snapshot from its report.

## AlertChannel

`SharedInWorkspace`. `type` (`slack|discord|webhook`), `url` (credential —
`toClientArray()` returns only a `url_preview`), `is_enabled`,
`last_delivered_at`, `last_error`. `monitors()` belongsToMany. `MAX_PER_USER=10`.

## StatusPage

`SharedInWorkspace`. `token` (public `/status/{token}`), `is_enabled`;
`monitors()` belongsToMany with `position`. `MAX_PER_USER=5`.

## WebhookEndpoint / WebhookCapture

`WebhookEndpoint`: `SharedInWorkspace`. `token` (`/hook/{token}`),
`expect_interval_minutes` (dead-man's switch), `last_status`
(`unknown|receiving|silent`). `isOverdue()`. `WebhookCapture`: method/headers/
query/body/ip; retention 100.

## McpProxy / McpProxyExchange

`McpProxy`: `SharedInWorkspace`. `token` (`/mcp-proxy/{token}` relay),
`upstream_url`, `last_used_at`. `McpProxyExchange`: recorded traffic —
method/request/response/status/duration_ms/flagged/flag_summary. Retention 200.

## InspectionReport

`SharedInWorkspace`. `type`
(`agent_loop|conformance|security|collection_run|mcp_drift|parity|exploration`),
`summary`, `data` (json), `share_token` (**hidden**, nullable — grants public
read at `/r/{token}`). `share()`/`revokeShare()`; two reports of the same type
diff over time.
