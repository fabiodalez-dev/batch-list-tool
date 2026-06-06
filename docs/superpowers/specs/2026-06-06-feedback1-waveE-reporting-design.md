# Feedback 1 — Wave E: Reporting Design

**Status:** Design only (no code this round). Confirm data availability and
exact wording with NAf before building.

**Context:** NAf's "Batch List Feedback 1" lists concrete reporting
use-cases the archive needs from the tool. This document maps each
use-case to the data we already hold, defines the report's inputs,
columns, grouping and output, and flags any data gaps. It builds on the
reporting infrastructure already in the codebase rather than inventing a
new one.

---

## Existing reporting infrastructure (reuse, don't reinvent)

| Piece | Where | Role |
|---|---|---|
| Canned report pages | `app/Filament/Resources/.../Reports`, `ReportTemplate::SOURCE_*` | Filterable, sortable, column-toggleable tables (Documents, Documents by Batch/Creator/Series, Pending Disinfestation, Box Movements, Flags by Type). |
| `ReportTemplate` | `app/Models/ReportTemplate.php` | Saves a filter + column + sort snapshot per report `source`, owner-scoped, optionally shared within a repository. |
| `GenericReportExport` | `app/Exports/GenericReportExport.php` | Streams any report's current rows to CSV/Excel. |
| Repository scope | `BelongsToRepository`, `RepositoryScope`, `ThroughBatchRepositoryScope` | Every report is implicitly tenant-scoped; super_admin/admin bypass. |

**Design rule:** each new report below is a new `ReportTemplate` *source*
plus a report page that reuses the existing table/filter/export plumbing.
Where a report needs an aggregation a flat table can't express, it gets a
purpose-built read-model (a query object), but still renders through the
same page/export components.

---

## Data inventory (the columns these reports rely on)

**`documents`** — `identifier`, `catalogue_identifier`, `document_type`,
`series_id`, `accession_id`, `current_box_id`, `location_id`, `batch_id`,
`repository_id`, `current_box_type` (RAS / IN_SITU / MAV / STVC),
`custody_status` (default `in_box`), `barcode_status` (IN/OUT/PERM_OUT),
`disinfestation_date` (canonical) + legacy `disinfestation_date_1..3`,
`is_in_disinfestation`, `nra_location` / `museum_location` (legacy text),
provenance columns `ras_batch_1/ras_box_1 … in_situ_box_1..3`.

**`boxes`** — `box_type` (RAS / IN_SITU / MAV / STVC), `box_number`,
`batch_id`, `parent_box_id` (RAS a IN_SITU lineage), `barcode`,
`barcode_status`, `location_id`, `disinfestation_date`, `is_legacy`.

**`batches`** — `batch_number`, `type` (MAIN_COLLECTION / NOTARY_ACCESSION),
N:N to `accessions` via `accession_batch` (Wave B).

**`locations`** — flat after Wave D: `type` (Room/Museum/Repository),
`code` (auto, the human key), `repository_id`.

**`box_movements`** — `document_id`, `from_box_id`, `to_box_id`,
`movement_date`, `reason`, `user_id` (provenance/audit trail).

**`document_flags`** — `type`, attached to a document (issue flags,
replacing colour-coding).

---

## Use-case 1 — Disinfestation cycle planning ("what's due next")

**Question NAf asks:** which boxes/documents are due for the next
disinfestation cycle, and how many "box equivalents" is that?

**Inputs (filters):** repository; as-of date; cycle length in months
(default to NAf's stated interval, e.g. every N months); batch / accession;
include/exclude items already `is_in_disinfestation`.

**Logic:**
- "Last disinfested" = `disinfestation_date` (fall back to the most recent
  of the legacy `disinfestation_date_1..3` when the canonical column is
  null — surface which one was used).
- "Due" = `last_disinfested + cycle_length <= as-of` **or**
  `last_disinfested IS NULL` (never disinfested → always due, shown first).
- **Box-equivalent count:** `current_box_type = 'Big Brown Box'` counts as
  **2** boxes (the planner constant already noted on `Document`); everything
  else counts as 1. This is the headline number NAf plans capacity against.

**Grouping / output:** group by box (and by batch), with a per-batch
subtotal of box-equivalents and a grand total. Columns: Batch, Box,
Box type, Last disinfested (+ source), Months since, Due date, Box-equiv,
Item count.

**Output:** screen table + CSV/Excel; saveable as a `ReportTemplate`
(new source `disinfestation_cycle`).

**Data gaps to confirm:** the canonical cycle interval; whether the
"Big Brown Box = 2" rule extends to other oversized types; whether
planning is per-box or per-document.

---

## Use-case 2 — Is a given document disinfested?

**Question:** for a specific document (or a list), has it been disinfested,
when, and through which cycle?

**Inputs:** document identifier / catalogue identifier (single or
multi-paste); or any standard document filter to batch-check a set.

**Logic / output:** a yes/no per document with the disinfestation timeline
already modelled by `Document::disinfestationTimeline()` (Current +
Legacy #1/#2/#3). Columns: Identifier, Disinfested? (Y/N), Latest date,
Full timeline, Current box, Batch. PERM_OUT documents must show a date
(the existing rule: PERM_OUT requires a `disinfestation_date`).

**Output:** screen + export; this is effectively a focused view of the
Documents report, so it can ship as a saved `ReportTemplate` on the
existing Documents source with a dedicated "Disinfestation" column set
rather than a brand-new page.

---

## Use-case 3 — RAS vs NRA box comparison

**Question:** reconcile original Rent-A-Store (RAS) boxes against the
In-Situ (NRA) boxes their contents were moved into — what came from where,
and is anything unaccounted for?

**Logic:**
- RAS boxes: `boxes.box_type = 'RAS'`. NRA/In-Situ boxes:
  `box_type = 'IN_SITU'`, linked back via `parent_box_id`.
- Per RAS box: list the child IN_SITU boxes (`parent_box_id`), the document
  counts on each side, and the documents whose provenance columns
  (`ras_batch_*/ras_box_*`) point at this RAS box but that now sit in an
  IN_SITU/other box.
- **Reconciliation flags:** RAS box with no child IN_SITU box; documents
  still marked in a RAS box; count mismatch between RAS provenance and
  current IN_SITU placement.

**Output:** grouped by RAS box → child IN_SITU boxes, with counts and a
"discrepancy" badge. Screen + export. New read-model (the parent/child +
provenance join is beyond a flat filter), rendered through the standard
page. New source `ras_vs_nra`.

**Data gaps:** how reliable the legacy `ras_*` provenance columns are vs
`box_movements` as the source of truth for "where it came from" — confirm
which NAf trusts.

---

## Use-case 4 — NRA stock-take

**Question:** a complete inventory snapshot of what NRA currently holds.

**Inputs:** repository; batch / accession; series; box type; barcode
status; as-of date.

**Logic / output:** counts of boxes and documents by Batch → Box, with
roll-ups by box type (RAS/IN_SITU/MAV/STVC) and by barcode status
(IN/OUT/PERM_OUT). Headline totals: total boxes, box-equivalents, total
documents, documents currently OUT/PERM_OUT. Excludes nothing by default;
a "destroyed" toggle respects the box-destroyed provenance flags.

**Output:** a summary table (totals) plus a drill-down detail table; both
exportable. New source `stock_take`.

**Data gaps:** whether MAV/STVC legacy boxes should be in the headline or
shown separately; the definition of "in stock" for PERM_OUT items.

---

## Use-case 5 — Document location finder

**Question:** given a document, where is it physically — the exact NRA
location, or, if not yet migrated, which RAS box?

**Logic (resolution order):**
1. If `current_box_id` resolves to an IN_SITU box with a `location_id` →
   report the canonical **Location** (`locations.code` + type), the box,
   batch and accession.
2. Else if it is still in a RAS box → report the **RAS box / batch** and
   flag "not yet migrated to NRA".
3. Fall back to legacy `nra_location` / `museum_location` free-text only
   when no structured location exists, clearly labelled "legacy text".

**Inputs:** identifier / catalogue identifier (single or multi); output one
row per document. Columns: Identifier, Where (Location code or RAS box),
Resolution source (structured / RAS / legacy text), Box, Batch, Accession,
Barcode status.

**Output:** screen + export. Read-model over the box/location/provenance
chain; new source `location_finder`.

**Data gaps:** how many documents still rely on legacy text only (drives
how prominent step 3 must be); whether museum items need a distinct
"museum" answer.

---

## Use-case 6 — Box itemisation

**Question:** list everything inside a given box (a packing list /
shelf-check sheet).

**Inputs:** box (by barcode or batch + box number); optional "include
movement history".

**Logic / output:** all documents with `current_box_id = box`, ordered by
identifier, with the box header (box number, type, batch, location,
barcode + status, last disinfested). Optional appendix: the box's
`box_movements` history. Footer totals: item count, box-equivalent weight.

**Output:** print-friendly screen layout + CSV/Excel + (later) a formatted
PDF "box contents sheet". New source `box_itemisation`.

**Data gaps:** whether NAf wants a single-box sheet, a multi-box batch run,
or both; PDF layout requirements.

---

## Cross-cutting design decisions

- **One engine, many sources.** Every report is a `ReportTemplate` source
  + page reusing the existing filter/column/sort/export components.
  Aggregation-heavy reports (1, 3, 4, 5, 6) get a dedicated query
  read-model but render and export through the same plumbing.
- **Tenant safety.** All reports inherit `RepositoryScope` /
  `ThroughBatchRepositoryScope`; cross-repository totals are visible only
  to super_admin/admin.
- **Saveable & shareable.** Each report can be bookmarked as a
  `ReportTemplate` (owner-scoped, optionally shared in-repository), so
  recurring runs ("this quarter's disinfestation due list") are one click.
- **Export parity.** Whatever a report shows on screen, it exports
  identically via `GenericReportExport` (CSV + Excel); box itemisation
  additionally targets a print/PDF sheet in a later round.
- **Legacy-data honesty.** Reports that fall back to legacy columns
  (`disinfestation_date_1..3`, `nra_location`/`museum_location`,
  `ras_*` provenance) must label the fallback, never present it as
  structured data.

## Build order (when greenlit)

1. Use-case 2 + Use-case 6 — thin, mostly views over existing data; quick wins.
2. Use-case 4 (stock-take) — aggregation read-model, high operational value.
3. Use-case 1 (disinfestation planning) — depends on confirmed cycle rules.
4. Use-case 5 (location finder) — depends on provenance trust decision.
5. Use-case 3 (RAS vs NRA) — most reconciliation logic; build last.

## Open questions for NAf (blockers before coding)

1. Disinfestation cycle length, and whether planning is per-box or per-document.
2. Is the "Big Brown Box = 2" weighting the only oversize rule?
3. Source of truth for provenance: legacy `ras_*` columns vs `box_movements`.
4. Treatment of MAV/STVC legacy boxes and PERM_OUT items in stock-take.
5. Box itemisation: single sheet vs batch run; PDF layout needs.
