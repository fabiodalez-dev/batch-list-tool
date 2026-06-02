# Per-repository Custom Fields — Design Spec

**Goal:** super_admin can define custom fields **per repository**, scoped to an
entity level (Document, Batch, Box, Volume). Each field has a type (text,
textarea, number, boolean, date, datetime, select, email, url). Field values are
enterable in create/edit forms, shown on the view page, available as toggleable
table columns, and included in CSV/Excel export + bulk import. Definition is
restricted to super_admin; values follow the record's normal edit/view access
(field-permission matrix NOT integrated in v1).

**Stack:** Laravel 13 + Filament 5 (Schemas API), MySQL local / **MariaDB prod**
(migrations MUST be cross-engine + idempotent; deploy runs `migrate --force`).

---

## Architecture — EAV, repository-scoped

### Table `custom_field_definitions`
- `id`
- `repository_id` → FK repositories, cascadeOnDelete, indexed
- `entity_type` string(16) — one of: `document|batch|box|volume`
- `key` string(64) — machine key, snake_case, used as the storage/array key
- `label` string(128)
- `type` string(16) — `text|textarea|number|boolean|date|datetime|select|email|url`
- `options` json nullable — for `select`: array of `{value,label}`; null otherwise
- `is_required` boolean default false
- `is_active` boolean default true
- `help_text` string(255) nullable
- `sort_order` integer default 0
- timestamps
- UNIQUE (`repository_id`,`entity_type`,`key`)
- INDEX (`repository_id`,`entity_type`,`is_active`,`sort_order`)

### Table `custom_field_values`
- `id`
- `custom_field_definition_id` → FK custom_field_definitions, cascadeOnDelete, indexed
- `customizable_type` string + `customizable_id` bigint — polymorphic (morphs) to
  Document/Batch/Box/Volume. Use `$table->morphs('customizable')`.
- `value` text nullable (all types serialized to string/JSON; cast in the model)
- timestamps
- UNIQUE (`custom_field_definition_id`,`customizable_type`,`customizable_id`)

**Cross-engine notes:** no `->after()`; no DB-level CHECK needed; `json` column
type works on both MariaDB 10.11 and SQLite. Guard with
`Schema::hasTable()` so re-runs are safe. Keep one migration file per table.

---

## Models

### `app/Models/CustomFieldDefinition.php`
- `$fillable`: repository_id, entity_type, key, label, type, options, is_required,
  is_active, help_text, sort_order
- casts: options => array, is_required => bool, is_active => bool, sort_order => int
- `repository(): BelongsTo`
- `values(): HasMany(CustomFieldValue)`
- const `ENTITY_TYPES = ['document'=>Document::class,'batch'=>Batch::class,'box'=>Box::class,'volume'=>Volume::class]`
- const `TYPES = ['text','textarea','number','boolean','date','datetime','select','email','url']`
- Auditable (implements AuditableContract, use Auditable) — match existing models.

### `app/Models/CustomFieldValue.php`
- `$fillable`: custom_field_definition_id, value
- `definition(): BelongsTo`
- `customizable(): MorphTo`
- accessor `getTypedValueAttribute()` casting `value` per definition type
  (bool→(bool), number→numeric, date/datetime→Carbon, select→**string** (v1: single-select
  only, stored and returned as a plain string; multi-select JSON-array is out of scope,
  see Non-goals), else string).

### Trait `app/Models/Concerns/HasCustomFields.php`
Applied to Document, Batch, Box, Volume.
- `customFieldValues(): MorphMany(CustomFieldValue, 'customizable')`
- `customFieldEntityType(): string` — returns the entity key. Implement by a static
  map keyed on class (document/batch/box/volume).
- `resolveRepositoryId(): ?int` — Document/Batch: `$this->repository_id`;
  Box: `$this->batch?->repository_id`; Volume: `$this->document?->repository_id`.
  Implement per-model by overriding a method the trait calls, OR the trait reads a
  public `$customFieldRepositoryResolver`. Simplest: trait defines
  `customFieldRepositoryId(): ?int { return $this->repository_id; }` and Box/Volume
  OVERRIDE it. (Document/Batch inherit the default.)
- `customFieldDefinitions()` helper: query CustomFieldDefinition where
  repository_id = customFieldRepositoryId(), entity_type = customFieldEntityType(),
  is_active = true, ordered by sort_order.
- `getCustomFieldData(): array` — `[key => typed value]` from saved values.
- `setCustomFieldData(array $data, bool $replaceMissing = true): void` — upsert/delete
  CustomFieldValue rows for this record against the active definitions (only keys that
  belong to a definition). When `$replaceMissing=true` (default, form semantics), definitions
  absent from `$data` are deleted. When `$replaceMissing=false` (import/merge semantics),
  only keys present in `$data` are processed; absent keys are left untouched.

---

## Admin CRUD — RelationManager on RepositoryResource

`app/Filament/Resources/RepositoryResource/RelationManagers/CustomFieldsRelationManager.php`
- `protected static string $relationship = 'customFieldDefinitions';`
  (add `customFieldDefinitions(): HasMany` to `Repository` model)
- Title: "Custom fields". recordTitleAttribute: 'label'.
- **Visible/usable only to super_admin**: override `canViewForRecord()` /
  `can*()` to `auth()->user()?->hasRole('super_admin')`. At minimum gate
  `isReadOnly()`/header+row actions on super_admin.
- Form (Filament 5 `Schema`): entity_type (Select: document/batch/box/volume),
  label (TextInput, required), key (TextInput, required, alphaDash, lowercase;
  auto-suggest from label; immutable on edit), type (Select of TYPES, live),
  options (Repeater value/label, visible only when type=select, required then),
  is_required (Toggle), is_active (Toggle default true), help_text (TextInput),
  sort_order (TextInput numeric default 0).
- Validation: unique (repository_id,entity_type,key) — scope the rule to the
  owner record's repository.
- Table columns: entity_type (badge), label, key, type (badge), is_required (icon),
  is_active (icon), sort_order. Filter by entity_type. Reorderable by sort_order.
- Register in `RepositoryResource::getRelations()`.

---

## Form injection (the 4 resources)

For Document, Batch, Box, Volume resources `form()`: append a
`Section::make('Custom fields')` that renders one Filament field per active
definition for **that record's repository + entity_type**, keyed under a `custom`
array (e.g. `custom.{key}`). On create the repository may not be chosen yet — for
Document/Batch read the repository from the form state `repository_id` (live) or
the user's default; for Box via selected batch; for Volume via selected document.
Simplest robust approach: render definitions for the user's
**default repository** when no record/parent is set yet, and re-resolve on edit
from the actual record. Acceptable for v1.

Mechanism (no schema changes to host tables):
- Custom values live in `custom_field_values`, not on the host table.
- Use the page lifecycle: `mutateFormDataBeforeFill` to load `custom.*` from
  `getCustomFieldData()`; `afterSave`/`afterCreate` hooks (or a saved/created model
  observer) to persist via `setCustomFieldData($data['custom'] ?? [])`.
- Put the load/save glue in a reusable trait
  `app/Filament/Concerns/HandlesCustomFieldForm.php` used by the Create/Edit pages
  of the 4 resources, plus a helper that builds the schema array
  (`App\Support\CustomFields\CustomFieldSchema::for($entityType,$repositoryId)`).
- Map types → Filament components: text→TextInput, textarea→Textarea,
  number→TextInput numeric, boolean→Toggle, date→DatePicker, datetime→DateTimePicker,
  select→Select (multiple if options flagged; v1 single), email→TextInput email,
  url→TextInput url. Apply `->required()` when is_required, `->helperText(help_text)`.

---

## View, table, export, import

- **View/infolist**: append a "Custom fields" section listing label → typed value
  for active definitions with a stored value.
- **Table**: for each active definition add a `TextColumn` `toggledHiddenByDefault()`
  reading the value via the relation; keep it lightweight (eager-load
  `customFieldValues.definition`). Acceptable to limit table columns to Document for
  v1 if perf is a concern, but implement for all 4 if clean.
- **Export** (Document already streams CSV): include active custom field columns
  after the fixed columns. **v1 scope: Document export only.** Other resources
  (Batch, Box, Volume) are not included in v1 — the helper is structured so they
  can adopt the pattern in a future iteration; Document export MUST include them.
- **Import**: DocumentImporter (and the others if low-cost) should accept columns
  named after the field `label`/`key` and route them into `setCustomFieldData`.
  v1 minimum: Document import maps recognised custom-field columns.

---

## Permissions
- Defining custom fields: **super_admin only** (RelationManager gates).
- Entering/seeing values: whoever can edit/view the host record (no extra gate).
- Field-permission matrix integration: OUT OF SCOPE for v1 (note in code).

---

## Tests (Pest, RefreshDatabase, cross-engine)
- Unit: definition create + unique constraint; value typed-cast per type;
  trait resolveRepositoryId for the 4 entities (incl. Box→batch, Volume→document);
  getCustomFieldData/setCustomFieldData upsert+delete.
- Feature: RelationManager visible to super_admin, hidden/forbidden to plain admin;
  create a definition through it; repository scoping (def in repo A not shown in repo B).
- Livewire/form: for each of the 4 resources, a definition renders in the form and a
  submitted value persists + reloads on edit; required validation fires.
- Export: a Document custom field value appears in the CSV stream.
- All migrations run clean on SQLite; suite green; Pint clean; PHPStan 0.

## Non-goals (v1)
- Multi-select options UI beyond single select (json-ready, but single in v1).
- Field-permission-matrix integration.
- Conditional visibility / computed fields.
