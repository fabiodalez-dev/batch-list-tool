# Field-level permissions — scope (RFQ §3.1.8)

This note formally delimits where the **field-level** permission matrix
(`config/field_permissions.php`, resolved by `App\Support\FieldPermissions`,
editable at Settings → "Field permissions") applies, and where access is
governed by **resource-level** RBAC only. Both layers are always active; the
distinction is whether *individual fields* within a resource are gated.

## Two layers of access control

1. **Resource-level RBAC** — Spatie permissions + Filament Shield decide
   *"can role X view / create / update / delete this resource at all?"*.
   Applied to **every** Filament resource via its Policy + Shield-generated
   permissions. This is the baseline and is exhaustive.

2. **Field-level matrix** — of the fields visible on a resource, decides
   *"which can role X read / write, and which are hidden?"*. Applied where a
   resource exposes **sensitive or business-critical fields** that need a
   finer grain than the resource verb.

## Resources WITH a field-level matrix

These are the entities that carry sensitive / invariant-bearing fields, so
they are explicitly modelled in `config/field_permissions.php`:

| Resource | Why field-gated (examples) |
|---|---|
| `document` | tenant binding (`repository_id`), schemaless `extra`, identifier, disinfestation, custom JSON |
| `authority` | stable cross-table key (`identifier`) is admin-only-write |
| `series` | `is_wills_series` / `is_active` drive the batch-50 + listing rules |
| `batch` | `repository_id`, `type` (collection allocation), `is_active` |
| `box` | `box_type` / `is_legacy` gate the MAV/STVC legacy rule |

A test (`ComplianceGapsRound2Test`) asserts the matrix declares all five.

## Resources WITHOUT a field-level matrix (resource-level RBAC only)

The remaining resources are governed by resource-level RBAC because they have
**no per-field sensitivity beyond the resource verb**:

- **Reference / lookup data** — `location`, `document_type`, `practice`,
  `accession`, `volume`: every field is operational reference data; if a role
  may edit the resource, it may edit all its fields.
- **Read-only / system surfaces** — `audit` (immutable log), `report` /
  `report_template` (no PII fields), `import_profile` (owner-scoped config).
- **Administration** — `user`, `repository`, `role`: managed only by
  Administrators; field-level sub-gating would add no security value because
  the resource itself is admin-only.

## Adding field-level control to another resource

The matrix is forward-compatible: an unlisted resource defaults to
implicit-allow (all four roles read+write), so adding a new resource never
silently locks users out. To gate a new resource's fields, add an entry to
`config/field_permissions.php` and wrap the relevant form components with the
resource's `gateField()` helper. `super_admin` always retains full access.

## Decision

For RFQ-2026-06 the field-level matrix is **deliberately scoped to the five
core domain entities above**, which hold every sensitive / invariant-bearing
field. All other resources rely on resource-level RBAC. If NAF requires
field-level gating on a specific additional resource, it is a bounded,
config-driven addition (no architectural change) and can be added at UAT.
