# Role Taxonomy — RFQ-2026-06 mapping

The contract (RFQ §3.3 + submission) names three operator roles. The system
implements them with the Filament Shield / Spatie convention slugs, which are
the stable internal identifiers wired into `App\Support\FieldPermissions`,
every `app/Policies/*` class, the Shield permission matrix, and the test
suite. The RFQ display names are surfaced in the UI via
`App\Support\RoleLabels`.

| RFQ / submission name | Internal slug | Capabilities |
|---|---|---|
| **Administrator** | `super_admin` | Full access incl. role/permission management, Shield, every gate bypass. The emergency escape-hatch account (`admin@batchlist.local`) holds this. |
| **Administrator** | `admin` | Full operational access (CRUD on all entities, import, reports) but no Shield/role management. The NAF committee accounts (Charlene, Maria Pia, Massimo) currently hold `super_admin` at the client's explicit request. |
| **ReadingRoom** | `editor` | Create + update documents; cannot delete; no user management. |
| **General** | `viewer` | Read-only: consultation, search, export. No mutations. |

## Why the slugs are not renamed

Renaming the four slugs to the RFQ names would require touching, in lockstep:

- `config/field_permissions.php` — the per-field read/write/hidden matrix keys
- all 13 `app/Policies/*` classes
- the Shield-generated permission seed in `InitialDataSeeder`
- ~900 Pest assertions that reference the slugs by string

…for a purely cosmetic gain. The mapping layer (`RoleLabels`) gives the
client the contractual names where they are user-visible, while keeping the
internal identifiers stable. This is the standard "presentation name vs.
system identifier" separation.

## If NAF requires the literal slug rename

It is feasible but should be done as a single dedicated migration PR with:
a data migration renaming the `roles.name` values, a sweep of the four
references above, and a full test re-run. Estimate: ~0.5 dev-day. Flag it
at UAT if the client insists on the literal names in the database.
