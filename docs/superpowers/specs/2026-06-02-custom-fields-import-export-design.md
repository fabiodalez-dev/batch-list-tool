# Custom Fields in Import/Export Excel — Design Spec (round 2)

**Goal:** The per-repository custom fields (built in the prior round) must be
present in the **import template (.xlsx)**, the **export (CSV/Excel)**, and be
**importable by the correct column** — dynamically, per entity, for Document,
Batch, Box, and Volume. The import template must be generated dynamically so the
custom-field columns for the *active* repository always appear (no empty/missing
columns); export must include the custom-field values where present.

**Scope (decided):**
- Repository source = the **active repository** (the topbar switcher /
  `App\Support\ActiveRepository`); when it is "All repositories" (null) fall back
  to the user's `default_repository_id`.
- Entities = **Document, Batch, Box, Volume**.
  - Document/Batch/Box already have export + import; extend them.
  - **Volume has NO importer and NO export today — both must be created** from
    scratch, following the existing patterns (BoxImporter / ListBoxes export /
    BoxResource template button).

**Stack:** Laravel 13 + Filament 5, PhpSpreadsheet (already used by
`App\Support\BulkImport\TemplateGenerator`). MySQL local / **MariaDB prod**
(migrations cross-engine + idempotent; but this round adds NO migrations).
Tests on SQLite via Pest (RefreshDatabase). NEVER touch the local MySQL DB,
NEVER `migrate:fresh`. No CDN. Comments in English.

---

## 1. Central resolver (kill the duplication)

Create `app/Support/CustomFields/CustomFieldResolver.php`:

```
final class CustomFieldResolver
{
    // Active repository id for the current request: ActiveRepository::id()
    // (the topbar switcher) when set, else the authenticated user's
    // default_repository_id, else null.
    public static function activeRepositoryId(): ?int

    // Active definitions for an entity ('document'|'batch'|'box'|'volume')
    // in the resolved repository, ordered by sort_order. Empty collection
    // when repo is null or no active definitions. Request-memoised per
    // (repoId|entityType) to avoid repeat queries within one request.
    /** @return \Illuminate\Database\Eloquent\Collection<int,CustomFieldDefinition> */
    public static function definitionsFor(string $entityType): \Illuminate\Database\Eloquent\Collection
}
```

- `activeRepositoryId()`: read `app(\App\Support\ActiveRepository::class)->id()`
  if the class resolves and returns non-null; else `auth()->user()?->default_repository_id`.
  Guard for unauthenticated/CLI (return null).
- Memoise in a static array keyed by "{repoId}:{entityType}"; expose a
  `flush()` for tests.
- Export, importers, and TemplateGenerator MUST all source definitions from
  this resolver — no more ad-hoc `default_repository_id` lookups duplicated in
  each file. Refactor the existing Document export + DocumentImporter to use it.

CSV/template column key convention (keep consistent everywhere):
- header label shown to the operator = definition `label`
- internal/import column name = `cf_{key}`  (matches what DocumentImporter
  already uses). Importers must accept BOTH the label and `cf_{key}` /
  `{key}` as the incoming column header (operators may keep either).

---

## 2. TemplateGenerator — dynamic custom-field columns

Modify `app/Support/BulkImport/TemplateGenerator.php`:

- `headersFor(string $entity): array` keeps returning the existing STATIC base
  headers, then APPENDS the active custom-field labels for that entity from
  `CustomFieldResolver::definitionsFor($entity)` (document/batch/box/volume).
  Order: static columns first, then custom columns by `sort_order`.
- Add `volume` to `TEMPLATES` and a `synthesiseVolumeHeaders()` mirroring the
  new VolumeImporter columns (document_identifier, volume_number, dates_start,
  dates_end, notes — confirm against the VolumeImporter built in §4).
- The hidden `_template_meta` sheet gains a row listing the custom-field keys
  included (so a stale-template check can tell custom columns apart).
- Keep the byte-for-byte legacy header contract for the STATIC part — only
  append after it. Duplicated legacy headers stay as-is.
- `download()`/`buildSpreadsheet()` unchanged except they now receive the
  longer header list. Filename unchanged.

Because headers are now repository-dependent, `headersFor()` is no longer a
pure constant — tests must seed definitions + set the active repo to assert the
appended columns. Document this in the method docblock.

---

## 3. Export — all four entities

Pattern reference: `app/Filament/Resources/DocumentResource/Pages/ListDocuments.php::exportToCsv()`
(already appends `cf_{key}` columns + values). 

- **Document**: refactor to source defs via `CustomFieldResolver::definitionsFor('document')`
  (drop the local `getActiveCustomFieldDefinitions()` default-repo lookup).
- **Batch** (`ListBatches.php`) and **Box** (`ListBoxes.php`): they already have
  an export action — append the active custom-field columns + per-row typed
  values exactly like Document (eager-load `customFieldValues.definition`;
  reuse the same value-formatting: boolean→1/0, date→Y-m-d, datetime→Y-m-d H:i:s,
  else string; sanitizeCsvCell on user strings). Box repo is derived via batch.
- **Volume**: NEW export. Add an `exportToCsv()` to `ListVolumes` mirroring the
  others; fixed columns = the Volume's own (document identifier, volume_number,
  dates_start, dates_end, notes) + custom columns. Wire an "Export CSV" header
  action gated on `view_any_volume` (or the existing volume view ability).

Factor the shared value-formatting into a small trait/helper if it reduces
copy-paste (e.g. `App\Support\CustomFields\CustomFieldCsv::format($def,$typed)`)
— but do not over-engineer; matching the Document code is acceptable.

---

## 4. Import — all four entities

Pattern reference: `app/Filament/Imports/DocumentImporter.php`
(`getColumns()` = static + `getCustomFieldColumns()`; `resolveCustomFieldDefinitions()`
cached per repo; `persistRowSideEffects()` calls `setCustomFieldData($data,false)`
merge semantics; empty mapped cell → null → that key cleared).

- **DocumentImporter**: refactor `resolveCustomFieldDefinitions()` to delegate to
  `CustomFieldResolver::definitionsFor('document')` (keep the per-repo cache
  behaviour the resolver now owns).
- **BatchImporter**, **BoxImporter**: add the same dynamic custom-field columns
  (`getCustomFieldColumns()` from the resolver for 'batch'/'box') and the same
  `persistRowSideEffects()`-style hook calling `setCustomFieldData($custom,false)`
  on the imported record. Each dynamic ImportColumn accepts the label and
  `cf_{key}`/`{key}` header variants and stashes the raw cell (null when blank).
- **VolumeImporter**: NEW. Model it on BoxImporter. Resolve/attach the parent
  Document by its identifier (the Volume belongs to a Document); static columns:
  document_identifier (required, resolves document_id via EntityResolver or a
  direct lookup scoped to the active repo), volume_number, dates_start,
  dates_end, notes. Then the dynamic custom columns for 'volume'. Respect
  multi-tenant: the resolved document must belong to the active repository.
- Wire the Volume import + template-download header actions into
  `ListVolumes` (mirror ListBoxes: FullImportAction with VolumeImporter +
  "Download template" calling `TemplateGenerator::download('volume')`).

Type coercion on import (all importers): cast the incoming cell to the
definition type before storing — boolean ("1"/"0"/"true"/"yes" → bool),
number (numeric), date/datetime (parse to Y-m-d / Y-m-d H:i:s), select
(validate against the definition options; ignore/raise on unknown? → v1: store
as-is if it matches an option value/label, else skip that cell and let the row
import without it). Keep it lenient: a bad custom cell must NOT fail the whole row.

---

## 5. Tests (Pest, RefreshDatabase, SQLite)

For EACH of the 4 entities:
- **Template**: seed 2 active custom defs (e.g. a text + a date) on repo A for
  that entity; set active repo = A; assert `TemplateGenerator::headersFor($e)`
  contains the static headers AND both custom labels, in order; assert a def on
  repo B is ABSENT when active repo = A (isolation); assert an inactive def is absent.
- **Export**: create a record with a custom value; assert the exported CSV
  header includes the custom column and the row carries the formatted value
  (text, boolean→1/0, date→Y-m-d).
- **Import**: build a row keyed by the custom label / cf_key; run the importer;
  assert the value persisted via the EAV with the correct typed cast; assert a
  blank mapped cell leaves/clears appropriately (merge semantics); assert a
  custom column for repo B is not applied when importing into repo A.
- **Volume**: also cover the brand-new importer/export base columns (document
  resolution by identifier, tenant check).
- Resolver unit: activeRepositoryId() prefers ActiveRepository over default;
  definitionsFor() memoises + isolates by repo + entity + active flag.

Run: `./vendor/bin/pest tests/Feature/CustomFields/ tests/Feature/Import/ tests/Feature/Resources/`
plus any TemplateGenerator test file. Then full suite green, Pint clean, PHPStan 0.

## Non-goals (this round)
- No new DB migrations (uses the existing custom_field_* tables).
- No multi-select beyond single select (unchanged).
- Field-permission-matrix integration still out of scope.
- No change to the legacy STATIC header contract (only append after it).
