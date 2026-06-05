# Feedback 1 — Decisions & Design Spec

**Context:** single production installation (archivetool.eu, MariaDB). No external
consumers, no backward-compat to preserve → we change the schema/labels to match
NAf's requests directly and make the whole codebase consistent. Decisions below
are FINAL for this round (the user delegated them); each answers a point or open
question in NAf's "Batch List Feedback 1".

**Stack:** Laravel 13 + Filament 5. Migrations cross-engine (MariaDB + SQLite),
idempotent, `Schema::hasTable/hasColumn` guards, explicit SHORT index names
(MariaDB 64-char limit). Deploy runs `migrate --force`. Queue is `database`.
Tests on SQLite via Pest; NEVER migrate:fresh / touch local MySQL.

Implementation order = Waves A→E below. Each wave ships as its own PR.

---

## DECISIONS on NAf's open questions

1. **Batch ↔ Accession = N:N (CONFIRMED).** New pivot `accession_batch`
   (accession_id, batch_id, timestamps, unique pair). Drop `accessions.batch_id`
   after migrating existing links into the pivot. An accession spans many
   batches; a batch is compiled from many accessions (Batch 50 = wills from
   several notaries).

2. **Import is BOTTOM-UP from the Document row.** One accession import sheet, one
   row per document. For each row, in order: resolve/create Authority →
   Accession → Batch → Box, then create/link the Document and attach its
   relations. Anything missing is auto-created from the row's columns; anything
   existing is linked. (Replaces the per-entity top-down importers for the
   "new accession" flow; the per-entity importers stay for reference data.)

3. **Multi-author in the sheet (my recommendation).** A document can have several
   authorities. Sheet columns:
   - `Authority Identifier` — one or many, delimited by `;` (e.g. `R12;R88`).
     Identifier is the SOURCE OF TRUTH for linking.
   - `Authority Name`, `Authority Surname` — optional, same `;` order, used only
     to VALIDATE the identifier (mismatch → row error), never to auto-pick.
   Rationale: names are ambiguous (two "Paolo Vassallo"); identifiers are unique.
   On create of a brand-new authority, identifier + name + surname are required
   and entity_type defaults to **Notary**; practice dates left blank.

4. **New Document identifier at accession time.** The document's working
   identifier = `{first Authority Identifier}/{Box No}/{running seq within box}`
   is NOT imposed; instead we use the operator-provided **`identifier`** column
   if present, else auto-generate `{AccessionNo}-{BoxNo}-{rowSeq}` as a
   provisional handle. `catalogue_identifier` stays BLANK (not catalogued yet).
   Document the chosen scheme in the importer.

5. **New field `part_number`** on documents (string, nullable). Add to model,
   form, table (toggle), export, import sheet.

6. **Rename "Batch Type" → "Accession Type".** It is a lookup of accession kinds
   (NA ref code). Rename the user-facing label and the lookup resource; keep the
   `type` column on batch but validate against the renamed lookup.

7. **Volume stays a field on the Document, not a separate section.** NAf: "isn't
   the volume recorded in the Document?" → yes. Rename `volume_label` →
   `volume_number` (label "Volume No"). The standalone Volume resource/importer
   built earlier is REMOVED from the nav for the accession flow (kept only if it
   still has a distinct purpose — decision: hide it; volume is a document field).

8. **Location: drop `parent_location` chain; type-only.** Location types limited
   to **Room / Museum / Repository** (editable lookup). Location belongs to a
   Repository (choose repo first). `code` auto-generated, still visible (used as
   the import key — call it **Identifier**). Remove `depth`. `sort_order` kept
   internally but hidden from the simple UI.

9. **Series ↔ Repository ↔ DocumentType.** Series gains `repository_id` (a repo
   has its own series) and a N:N link to DocumentType (`document_type_series`).
   DocumentType gains an `identifier` (unique) used in import. Series `code` →
   labelled **Identifier**.

10. **Field-purpose cleanups (NAf "what is X for?"):**
    - `documents.barcode` — the barcode belongs to the BOX, not the document.
      Remove the standalone document-barcode field from the accession UI; the
      document inherits status from its box. (Keep legacy barcode history columns
      out of the accession form — "blank for now" per NAf.)
    - `nra_location` / `museum_location` vs `location_id` — collapse to a single
      `location_id` (FK) for the canonical location; the two free-text legacy
      fields are hidden from the accession form.
    - `dates_precise` — undocumented; HIDE from UI pending NAf clarification.
    - `is_active` on Batch — not meaningful as mandatory; make it non-mandatory
      (default true) and explain in the About text, or hide. Decision: keep
      column, drop the "required" marker.
    - `parent_box_id` / `is_legacy` on Box — internal provenance; hide from the
      simple Box form (kept in DB for legacy data).
    - **Digitisation Statuses lookup — REMOVE from nav** (NAf: "can be removed").
    - `current_box_type` — keep (RAS/IN_SITU/…); validate ref code on import.
    - Custody status default at import = **In Box**.

11. **Mass import of the CURRENT batch list** = same bottom-up engine as new
    accessions, driven by the updated Batch_List template (incl. Flags columns).
    No separate top-down path. (NAf asked for advice → bottom-up, one engine.)

---

## WAVE A — Bug fix + quick wins (no schema risk, ship first)

A1. **Import never runs (BUG).** `QUEUE_CONNECTION=database` + no worker/cron on
    cPanel ⇒ queued import jobs sit in `jobs` forever ("3 rows will be processed"
    but nothing imports; 5 stuck jobs observed in prod). Fix options, pick the
    robust one for shared hosting:
    - Add a cron `* * * * * cd /home/archivet/public_html && php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1`
      (drains the queue every minute), AND
    - surface an **Import status** page/widget (list recent `imports` rows with
      processed/total/failed + a link to the failed-rows download).
    Document the cron in `docs/operations/`.
A2. Batch "already exists" → friendly validation message "Batch number already
    exists." (catch the unique violation in the form, not a 500 page).
    Also: suggest the next sequential batch number on the New Batch form.
A3. Label/casing fixes: "New batch"→"New Batch"; "Box"→"Box"/"Current Box"→"Box";
    "Code"→"Identifier" (Series, Box Types, Current Box Types, Barcode Statuses);
    "Notary Accession Number"→"Accession Number"; "Accession date"→"Accession
    Date"; "Code"(Accession)→"Title"; "Batch number"→"Batch Number".
A4. Export CSV (Batches) include Repository column.
A5. Per-column **sorting** everywhere; fix the sort-arrow overlapping the number.
A6. Tables: allow hiding preset columns + **drag-and-drop column reorder**
    (Filament `reorderableColumns()` + `toggleable()`), Boxes order requested:
    Batch / Box / Barcode / BarcodeStatus / DisinfestationDate / BoxType /
    Destroyed / ParentBoxId / IsLegacy.
A7. Filters: keep the filter UI visible when the result set is empty (don't hide
    it); add an explicit **Apply** button where missing (Notary Accessions).
A8. Only **Batch Number** is the hyperlink to the batch (not the whole row).
A9. Add **Inputter / creator name** column on each list (track record creator).
A10. Box: barcode **required + globally unique**, never null (form + importer +
     DB unique index).
A11. "About this page" simplified (placeholder until NAf provides the texts).

## WAVE B — N:N Batch↔Accession

B1. Migration `create accession_batch` pivot (unique [accession_id,batch_id],
    short index name e.g. `acc_batch_uq`).
B2. Data migration: every `accessions.batch_id` → a pivot row; then drop the
    column (single install, no compat).
B3. Models: `Batch::accessions(): BelongsToMany`, `Accession::batches():
    BelongsToMany`. Remove the old `hasMany/belongsTo`.
B4. UI: Batch form multi-selects its accessions; Accession form multi-selects its
    batches; "different accessions on the same batch" is now ALLOWED (remove the
    old guard); Batch.description auto-derived (editable) by concatenating the
    linked accession titles with ", ".
B5. Tenancy: pivot rows respect repository scope (both sides same repo).

## WAVE C — Bottom-up accession importer

C1. New `App\Support\BulkImport\AccessionRowImporter` (or a dedicated
    Filament Importer) that processes one document row top-of-cascade:
    Authority(;-multi) → Accession(s) → Batch(es, N:N) → Box → Document(+links).
C2. Per-cell validation with row-level errors (Batch No unique; box_number unique
    within batch; box barcode globally unique; authority identifier/name/surname
    consistency; document_type/series/practice/repository ref codes must exist →
    else row error). All errors surface in the failed-rows report.
C3. New TemplateGenerator entity `accession` whose columns are exactly the
    bottom-up sheet (see DECISIONS 3–5,10). Custom fields appended per active
    repository (reuse `CustomFieldResolver`).
C4. Wire into the Import Wizard as the primary "New accession" path.

## WAVE D — Series/DocumentType/Location/Practice model changes

D1. Series: `repository_id` (nullable→required on new), N:N `document_type_series`.
D2. DocumentType: add unique `identifier`.
D3. Location: type lookup (Room/Museum/Repository), auto `code`, drop parent
    chain + depth from UI, repo-first.
D4. Practice / DocumentType / Location: optional `identifier` used by import.
D5. `documents.part_number`; rename `volume_label`→`volume_number`.
D6. Hide/remove: Digitisation Statuses nav; standalone Volume nav; document
    barcode + nra/museum_location + dates_precise from accession form.

## WAVE E — Reporting (per NAf's listed use-cases)

E1. Reports: next disinfestation cycle; is-document-disinfested check;
    RAS-vs-NRA box comparison; NRA stock-take; document location finder
    (NRA exact location or RAS); box itemisation. (Design only this round;
    confirm data availability before building.)

---

## Non-goals / parked (NAf will give more feedback)
Flags redesign (replaces colour-coding), Cataloguing extras, Legacy box history
& legacy barcodes detail, Boxes "more data" WIP, attachments-on-accession detail
(Digriet/Conservation/Email) — stub the media collection in Wave B/D but defer
the UX.
