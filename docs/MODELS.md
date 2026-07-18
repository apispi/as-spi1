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
`#[Fillable]` / `#[Hidden]` do nothing in Laravel 11 and previously broke
registration (mass-assignment 500) and leaked the password hash + SCX key. Use:

```php
protected $fillable = ['name','email','password','is_admin','scx_api_key','google_id','avatar'];
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

**Relations:** `savedRequests()` (hasMany), `requestHistories()` (hasMany).

**Secrets never serialize** — password hash, `scx_api_key` (encrypted at rest,
decrypted on access, so it *must* be hidden), `api_token`, `registration_token`.

---

## SavedRequest

A user's saved request config. `protocol` in `rest|mcp|a2a`.

- **Fillable:** user_id, name, protocol, method, url, headers, body, params.
- **Casts:** headers → array, params → array.
- For MCP/A2A: `method` holds the JSON-RPC method name and `params` the
  arguments; `body` is used for REST.
- **Relation:** `user()`.
- **Business rule (enforced in controller, not model):** non-admin users are
  capped at `SavedRequestController::FREE_PLAN_LIMIT = 10`; admins exempt.

---

## RequestHistory

An automatically recorded outbound request (proxy/MCP/A2A) for an authenticated
user.

- **Constant:** `RETENTION_PER_USER = 200`.
- **Fillable:** user_id, protocol, method, url, params, body, status, time_ms.
- **Casts:** params → array.
- **`status`** is nullable — **null means the call failed** (no HTTP status).
- **Never stores request headers** (they carry credentials).
- **`static record(int $userId, array $attrs): void`** — inserts the row, then
  deletes rows older than the newest `RETENTION_PER_USER` for that user (keeps
  history bounded).
- **Relation:** `user()`.

---

## AdminAction (audit log)

Immutable record of privileged admin operations.

- **Fillable:** admin_id, action, target_user_id, target_email, details.
- **Casts:** details → array.
- **Relation:** `admin()` → belongsTo User (`admin_id`).
- `action` values: `promote_admin`, `demote_admin`, `delete_user`.
- **Design:** `target_user_id` is **not** a foreign key and `target_email` is a
  snapshot, so the audit entry **survives deletion of the target user**.
  `delete_user` records the deleted user's saved-request count in `details`.

---

## CatalogItem

One model backs all five catalog entity types (`agent|skill|connector|tool|
prompt`) and both the Catalog (all) and Active (`is_active=true`) admin
sections. Full behaviour in [CATALOG.md](CATALOG.md).

- **Constant:** `TYPES = ['agent','skill','connector','tool','prompt']`.
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
