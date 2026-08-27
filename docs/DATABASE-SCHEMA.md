# Database schema

Authoritative table/column reference. For model behaviour see
[MODELS.md](MODELS.md). All tables use `bigIncrements id` and Laravel
`created_at`/`updated_at` timestamps unless noted.

> **Primary source:** `database/migrations/` (29 migrations). Regenerate the
> live column list with `php artisan db:table <name>`.

---

## Migration order

```
0001_01_01_000000_create_users_table
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
2026_06_20  create_saved_requests_table
2026_06_20  add_is_admin_to_users_table
2026_06_22  add_scx_api_key_to_users_table
2026_06_22  add_scx_model_to_users_table
2026_07_15  add_protocol_columns_to_saved_requests_table
2026_07_15  create_admin_actions_table
2026_07_15  create_request_histories_table
2026_07_15  add_preferences_to_users_table
2026_07_16  add_api_token_to_users_table
2026_07_16  create_catalog_items_table
2026_07_17  add_google_oauth_to_users_table
2026_07_17  add_registration_flow_to_users_table
2026_08_05  create_inspection_reports_table
2026_08_14  create_environments_table
2026_08_20  add_assertions_to_saved_requests_table
2026_08_20  create_collections_table            (+ collection_steps)
2026_08_21  create_monitors_table               (+ monitor_results)
2026_08_26  create_alert_channels_table         (+ alert_channel_monitor)
2026_08_27  create_organisations_table          (+ users.organisation_id)
2026_08_27  add_soft_deletes_to_users_table
2026_08_27  preserve_admin_actions_when_an_admin_is_deleted
2026_08_27  add_drift_watch_to_monitors_table
2026_08_27  create_webhook_endpoints_table      (+ webhook_captures)
2026_08_27  create_status_pages_table           (+ monitor_status_page)
2026_08_27  create_mcp_proxies_table            (+ mcp_proxy_exchanges)
2026_08_28  add_contract_to_saved_requests_table
```

---

## Identity & auth

### `users`
Base Laravel columns plus:
- `organisation_id` (FK → organisations, nullable, `nullOnDelete`) — workspace
- `is_admin` (bool, default false)
- `scx_api_key` (encrypted, nullable), `scx_model` (string, nullable) — SCX AI
- `preferences` (json, nullable)
- `api_token` (string 64, unique, nullable) — **SHA-256** of the personal API key;
  `api_token_last_four`, `api_token_created_at`
- `google_id` (unique, nullable), `avatar` (nullable)
- `registration_token` (**bcrypt**, nullable), `registration_token_expires_at`
- `deleted_at` (**soft deletes**) — a soft-deleted user is excluded from every
  query including the auth provider, so deactivation locks the account out.

### `organisations`
`name`, `slug` (unique), `description` (nullable), `is_active` (bool). A user's
`organisation_id` defines their **shared workspace** (see MODELS.md
`SharedInWorkspace`).

### `admin_actions`
Audit log. `admin_id` (FK → users, **nullable, `nullOnDelete`**),
`admin_email` (snapshot), `action`, `target_user_id` (snapshot, not FK),
`target_email` (snapshot), `details` (json). Snapshots + null-on-delete mean the
log outlives both the acting admin and the target.

---

## Requests, history, catalog

### `saved_requests`
`user_id` (owner), `name`, `protocol` (rest|mcp|a2a|grpc|mqtt|amqp),
`method`, `url` (may hold a `{{templated}}` URL), `headers` (json), `body`,
`params` (json), `assertions` (json — `[{path,operator,expected,description}]`),
`contract` (json — inferred response schema baseline).

### `request_histories`
Per-user activity log (**not** shared across a workspace). `user_id`, `protocol`,
`method`, `url`, `params`, `body`, `status`, `time_ms`. Retained 200/user
(`RequestHistory::RETENTION_PER_USER`); secrets masked before storage.

### `catalog_items`
One table for all catalog types (`agent|skill|connector|tool|prompt|resource`),
distinguished by `type`. `name`, `slug`, `description`, `version`, `provider`,
`metadata` (json — shape depends on type; connectors carry `endpoint`/`protocol`/
`auth_header`), `is_active`. Unique `(type, slug)`. See [CATALOG.md](CATALOG.md).

---

## Environments, collections, runs

### `environments`
`user_id`, `name`, `variables` (json — `[{key,value,secret}]`), `is_default`.
Names unique per **workspace**; one default per workspace.

### `collections` / `collection_steps`
`collections`: `user_id`, `name`, `description`, `continue_on_failure`.
`collection_steps`: `collection_id`, `saved_request_id` (cascade), `position`,
`extract` (json — `[{name,path}]` pulling response values into later steps).

### `inspection_reports`
Saved, shareable, diffable results. `user_id`, `catalog_item_id` (nullable),
`connector_slug`/`connector_name` (snapshots), `type`
(`agent_loop|conformance|security|collection_run|mcp_drift|parity|exploration`),
`summary`, `data` (json), `share_token` (unique, nullable, **hidden**).

---

## Monitors & alerting

### `monitors`
`user_id`, `collection_id` (nullable), `environment_id` (nullable, `nullOnDelete`),
`name`, `type` (`collection|mcp_drift`), `target_url` (drift only),
`interval_minutes`, `is_enabled`, `alerts_enabled`, `last_status`
(`unknown|passing|failing`), `last_run_at`, `consecutive_failures`.

### `monitor_results`
`monitor_id`, `inspection_report_id` (nullable), `passed`, `time_ms`,
`passed_count`, `total`, `summary`. Retained 500/monitor.

### `alert_channels` / `alert_channel_monitor`
`alert_channels`: `user_id`, `name`, `type` (`slack|discord|webhook`), `url`
(a credential — never returned to the client in full), `is_enabled`,
`last_delivered_at`, `last_error`. Pivot attaches channels to monitors.

### `status_pages` / `monitor_status_page`
`status_pages`: `user_id`, `name`, `description`, `token` (unique — public URL),
`is_enabled`. Pivot lists the monitors a page publishes, with `position`.

---

## Inbound capture & the flight recorder

### `webhook_endpoints` / `webhook_captures`
`webhook_endpoints`: `user_id`, `name`, `token` (unique — the `/hook/{token}`
URL), `expect_interval_minutes` (nullable — dead-man's switch),
`alerts_enabled`, `last_received_at`, `last_status`
(`unknown|receiving|silent`). `webhook_captures`: `webhook_endpoint_id`,
`method`, `headers` (json — Authorization/Cookie redacted at capture), `query`,
`body` (truncated at 64 KB), `ip`. Retained 100/endpoint.

### `mcp_proxies` / `mcp_proxy_exchanges`
`mcp_proxies`: `user_id`, `name`, `token` (unique — the `/mcp-proxy/{token}`
relay URL), `upstream_url`, `is_enabled`, `last_used_at`.
`mcp_proxy_exchanges`: `mcp_proxy_id`, `method`, `request` (json), `response`
(json — Authorization never stored), `status`, `duration_ms`, `flagged`
(injection scanner hit), `flag_summary`. Retained 200/proxy.

---

## Ownership & sharing

Every resource table above carries `user_id` as the **creator**. Resources
tagged with the `SharedInWorkspace` trait (environments, saved requests,
collections, monitors, alert channels, webhook endpoints, status pages, mcp
proxies, inspection reports) are scoped by **workspace** (`user_id IN` the
organisation's members) for read/use/edit/delete — not by the single creator.
`request_histories` and user credentials are the deliberate exceptions and
stay personal. See [ARCHITECTURE.md](ARCHITECTURE.md) and MODELS.md.
