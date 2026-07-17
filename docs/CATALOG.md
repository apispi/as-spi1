# Catalog & Connectors

The admin **Catalog** and **Active** sections, and the connector **sync** that
populates them from live MCP/A2A servers. Data model in
[DATABASE-SCHEMA.md](DATABASE-SCHEMA.md#catalog_items) and
[MODELS.md](MODELS.md#catalogitem); UI shell in [FRONTEND.md](FRONTEND.md).

---

## 1. Concept

The Catalog holds five entity types — **agents, skills, connectors, tools,
prompts** — each rendered as a tab. Two admin sections view the same data:

- **Catalog** (`/catalog`) — everything available.
- **Active** (`/active`) — only items with `is_active = true`.

Activation is a workspace-wide boolean (not per-user). A **connector** is a
registered MCP or A2A server; **Sync**ing it imports what it exposes as Tools /
Prompts / Skills, turning the Catalog from hand-typed records into a live
reflection of connected servers.

All Catalog routes are **admin-only** (`auth` + `admin`), except
`GET /api/tools/active` which any authenticated user may read.

---

## 2. Data model

One table, `catalog_items`, distinguished by `type` (see schema doc). Model
`App\Models\CatalogItem`:

- `TYPES = ['agent','skill','connector','tool','prompt']`
- scopes `ofType($type)`, `active()`
- unique `(type, slug)`; `metadata` is a JSON blob whose shape depends on type.

**Connector `metadata`:** `{ endpoint, protocol: 'mcp'|'a2a', auth_header?,
last_synced_at? }`.
**Imported item `metadata`:** the item's schema (`inputSchema` for tools,
`arguments` for prompts, raw skill object for skills) **plus** `connector_slug`,
`endpoint`, `protocol`. The connector's `auth_header` is **never** copied here.

---

## 3. Admin CRUD API (`CatalogItemController`)

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/admin/catalog?type=&active=` | list items, optional type + active filter |
| GET | `/api/admin/catalog/counts?active=` | per-type counts for tab badges |
| POST | `/api/admin/catalog` | create item |
| PUT | `/api/admin/catalog/{item}` | update item |
| DELETE | `/api/admin/catalog/{item}` | delete item |
| POST | `/api/admin/catalog/{item}/toggle-active` | move between Catalog/Active |
| POST | `/api/admin/catalog/{item}/sync` | sync a connector (see §4) |

**Validation on create:**
- `type` in `TYPES`; `name` required; `description/version/provider` optional;
  `metadata` optional array; `is_active` optional bool.
- Connector wiring, validated when relevant:
  - `metadata.endpoint` — **required if `type === connector`**, must be a URL,
    must pass `PubliclyRoutableUrl` (SSRF).
  - `metadata.protocol` in `mcp|a2a`.
  - `metadata.auth_header` — optional string (≤1000).
- `slug` is generated server-side, unique per type (`name` slugified, with
  `-2`, `-3`… on collision).

`counts` returns every type key (zero-filled), respecting the `active` filter.

---

## 4. Connector sync (`ConnectorSyncController@sync`)

`POST /api/admin/catalog/{connector}/sync`:

1. **422** unless `type === connector`.
2. Read `metadata.endpoint` (**422** if missing) and `metadata.protocol`
   (default `mcp`).
3. **Re-validate the endpoint** with `PubliclyRoutableUrl` at sync time (DNS may
   have changed since creation) → **422** if not public.
4. Build headers: `Authorization: {auth_header}` if present.
5. Dispatch by protocol:
   - **MCP** (`McpClient`): `initialize()`, then import `tools/list` results as
     `tool` items; import `prompts/list` as `prompt` items — `prompts/list` is
     optional in the spec, so wrap it in try/catch and skip on failure.
   - **A2A** (`A2aClient`): fetch the agent card; import its `skills` as `skill`
     items.
6. Stamp connector `metadata.last_synced_at = now()`.
7. Respond `{ message, counts: { tools|prompts|skills … } }`. Upstream failure
   (any client throw) → **502** with the message.

### Import rules (`import()`)

- Slug is **connector-namespaced**: `"{connectorSlug}-{slug(name)}"`, so
  identically named items from different connectors never collide.
- **Idempotent:** `firstOrNew(['type','slug'])` then fill — re-syncing updates
  in place and **leaves `is_active` untouched**, so activations survive a
  re-sync.
- Stores `provider = connector.name`, the item's schema, and
  `{ connector_slug, endpoint, protocol }` — **never `auth_header`**.

---

## 5. Active tools in the tester (`ToolController@active`)

`GET /api/tools/active` — available to **any authenticated user** (active items
are workspace-wide). Returns active `tool` items that have an endpoint, exposing
only:

```json
{ "id", "name", "description", "provider", "endpoint", "protocol", "input_schema" }
```

It **never** exposes the connector `auth_header`. The Dashboard fetches this on
mount and passes it to `RequestPanel`, whose MCP mode shows an **Active tools**
dropdown; selecting one sets the URL to the tool's endpoint and fills a
`tools/call` template from `input_schema`. Credentials are supplied by the user
in the Headers tab — the stored connector auth is not injected.

This closes the loop: **connect → sync → activate → call from the tester**.

---

## 6. Frontend (`CatalogSection.vue`)

One component backs both routes, parameterised by `route.meta.section`
(`'catalog'` | `'active'`):

- Five tabs (Agents/Skills/Connectors/Tools/Prompts) with count badges from
  `/counts`.
- Table of items with Activate/Deactivate and Delete; **Sync** button on
  connector rows.
- "Add" form per tab; for the Connectors tab it also captures **endpoint,
  protocol, and optional auth header** (stored in `metadata`).
- The Active section requests `?active=1`; empty states differ per section.

---

## 7. Security summary

1. All Catalog write/admin routes require `auth` + `admin`.
2. Connector endpoints pass `PubliclyRoutableUrl` at **create and sync** time.
3. The connector `auth_header` is stored only on the connector row and is
   **never** copied onto imported items nor returned by `/api/tools/active`.
4. Sync is idempotent and preserves activation state.

---

## 8. Status & extension notes

Current state: agents/skills/prompts can be hand-authored; connectors + sync
populate tools/prompts/skills from real servers. Natural extensions (not yet
built): a Catalog **edit UI** (the `PUT` endpoint exists but no UI calls it),
**connector health checks** (ping + last-synced surfaced), and **active prompts**
in the tester (mirror of active tools using `prompts/get`).
