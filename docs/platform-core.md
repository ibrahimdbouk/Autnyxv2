# Platform core: organisation structure

Shared by every app (Root Cause, Task Execution, later Assortment). Turning an
app on for a tenant needs no new setup: the organisation is set up once.

## What it holds

| Concept | Where | Notes |
|---|---|---|
| Region → Area → Store | `stores.region`, `stores.area` → `location_nodes` (built by `HierarchySync`) | Case/spacing-insensitive. An empty region/area is removed unless someone manages it. |
| Who manages a region / area | `location_node_managers` | Admin → Regions & Areas, the user form, or a **Manages** column in the users file. |
| Operating role | `users.org_role`: `hq`, `area_manager`, `store_manager`, `associate` | Separate from admin rights (`is_tenant_admin`). Null = not set (pre-ladder behaviour). |
| Which stores a person sees | `OrgDirectory::storeScope()` (`User::storeScope()`) | Admins / HQ: all. Area manager: stores under managed nodes + linked. Store roles: linked only; none linked = none (fails closed). Role not set: linked, or all if none. |
| Escalation chain | `OrgDirectory::escalationChain($store)` | Store managers → area managers → regional managers → head office (admins when nobody is HQ). Empty levels skipped; leavers never included. |
| Departments | `departments` (department → section) | Added automatically from `products.department`; `Department::forProduct()` places a product (section first). |
| Store zones | `store_zones` | Aisle, endcap, chiller… optionally in a department. "Copy to other stores" on the store page. |
| On-site radius | `stores.geofence_radius_m` → tenant `settings.geofence_radius_m` → 150 m | `Store::isOnSite($lat, $lng, $accuracy)`; accuracy allowance capped at 100 m. |
| Language | `users.locale`: en / ar / ur / hi | For the mobile app and messages. |
| Leavers | `users.deactivated_at` via `UserLifecycle` | No sign-in (password or SSO), sessions ended, devices revoked, no e-mail (`DropDeactivatedRecipients`), signed links refused, hidden from pickers. Links kept; reactivation restores. |
| Push devices | `user_devices` via `DeviceRegistry` | Token encrypted, looked up by hash; a token moves to its new user; revoked on deactivation. |

## Imports

- **Stores:** `area` (no longer an alias of Region) and `geofence_radius_m` (20–2000).
- **Users:** the Role cell is read as an operating role plus, separately, admin
  rights: "Manager" → store manager (never admin); "Admin; Head office" → both.
  An unknown role is a warning and changes nothing. `language`, `manages`
  (region/area names; "Region > Area" for one area). Stores / Manages cells:
  blank unlinks; all recognised → exactly that list; any unrecognised → the
  recognised ones are added, nothing removed, row warned.
- Learned mappings for stores and users files were retired (per-type version,
  `CanonicalSchema::versionFor`); other file types keep theirs.

## Setup

Getting started is split into **Your organisation** (stores, regions and
managers, people, products, alert address) plus one section per app the tenant
has. A Task-Execution-only tenant never sees data-feed steps.
