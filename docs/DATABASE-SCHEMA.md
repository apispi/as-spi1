# Database schema

Authoritative table/column reference. For model behaviour see
[MODELS.md](MODELS.md). All tables use `bigIncrements id` and Laravel
`created_at`/`updated_at` timestamps unless noted.

---

## Migration order

```
0001_01_01_000000_create_users_table
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
2026_06_20_071123_create_saved_requests_table
2026_06_20_074226_add_is_admin_to_users_table
2026_06_22_083700_add_scx_api_key_to_users_table
2026_06_22_095250_add_scx_model_to_users_table
2026_07_15_160245_add_protocol_columns_to_saved_requests_table
2026_07_15_171717_create_admin_actions_table
2026_07_15_173322_create_request_histories_table
2026_07_15_230242_add_preferences_to_users_table
2026_07_16_071746_add_api_token_to_users_table
2026_07_16_071747_create_catalog_items_table
2026_07_17_050204_add_google_oauth_to_users_table
2026_07_17_054118_add_registration_flow_to_users_table
```

Plus Laravel defaults: `cache`, `cache_locks`, `jobs`, `job_batches`,
`failed_jobs`, `password_reset_tokens`, `sessions`.

---

## users

Assembled across the base migration and eight `add_*` migrations.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string, **nullable** | null for OAuth/email-first until set |
| email | string, unique | |
| email_verified_at | timestamp, nullable | set when email-first registration completes |
| password | string, **nullable** | null for OAuth-only accounts; cast `hashed` |
| remember_token | string, nullable | hidden |
| is_admin | boolean, default false | |
| scx_api_key | text, nullable | cast `encrypted`; hidden |
| scx_model | string, nullable | |
| preferences | json, nullable | cast `array` |
| api_token | string(64), unique, nullable | **SHA-256 hash** of personal API key; hidden |
| api_token_last_four | string(4), nullable | masked-display hint |
| api_token_created_at | timestamp, nullable | |
| google_id | string, unique, nullable | |
| avatar | string, nullable | Google profile picture URL |
| registration_token | string, nullable | **bcrypt hash** of email-verify token; hidden |
| registration_token_expires_at | timestamp, nullable | 60-min TTL |
| created_at / updated_at | timestamps | |

## saved_requests

| Column | Type | Notes |
|---|---|---|
| user_id | FK users, cascade | |
| name | string | |
| protocol | string, default `rest` | `rest\|mcp\|a2a` |
| method | string | HTTP verb (REST) or JSON-RPC method (MCP/A2A) |
| url | text | |
| headers | json, nullable | cast `array` |
| body | longtext, nullable | REST body |
| params | json, nullable | cast `array` (MCP/A2A args) |

## request_histories

| Column | Type | Notes |
|---|---|---|
| user_id | FK users, cascade | |
| protocol | string, default `rest` | |
| method | string | |
| url | text | |
| params | json, nullable | cast `array` |
| body | longtext, nullable | **headers deliberately not stored** |
| status | unsignedSmallInteger, nullable | null = request failed |
| time_ms | unsignedInteger, nullable | |

Index: `(user_id, created_at)`. Retention: newest 200 per user (trimmed on insert).

## admin_actions

| Column | Type | Notes |
|---|---|---|
| admin_id | FK users, cascade | acting admin |
| action | string | promote_admin \| demote_admin \| delete_user |
| target_user_id | unsignedBigInteger, nullable | **not a FK** — survives target deletion |
| target_email | string, nullable | snapshot |
| details | json, nullable | cast `array` (e.g. saved_requests_deleted) |

## catalog_items

| Column | Type | Notes |
|---|---|---|
| type | string | agent \| skill \| connector \| tool \| prompt |
| name | string | |
| slug | string | connector-namespaced for synced items |
| description | text, nullable | |
| version | string, nullable | |
| provider | string, nullable | connector name for synced items |
| metadata | json, nullable | cast `array` (see below) |
| is_active | boolean, default false | workspace-wide activation |

Unique `(type, slug)`; index `(type, is_active)`.

`metadata` shapes:
- **connector:** `{ endpoint, protocol: mcp|a2a, auth_header?, last_synced_at? }`
- **tool (imported):** `{ inputSchema, connector_slug, endpoint, protocol }`
- **prompt (imported):** `{ arguments, connector_slug, endpoint, protocol }`
- **skill (imported):** the raw A2A skill object + connector fields

> `auth_header` lives only on connector rows and is never copied onto imported
> items or exposed by read endpoints.

---

## Notes for recreation

- `name` and `password` on `users` are made nullable by later migrations
  (`add_registration_flow` and `add_google_oauth` respectively). If building
  fresh, they can be nullable from the start.
- Column modifications use native Laravel 11 `->change()` (no `doctrine/dbal`).
- Use `unsignedSmallInteger` for `status` (HTTP codes ≤ 599) and
  `unsignedInteger` for `time_ms`.
