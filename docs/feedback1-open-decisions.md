# Feedback 1 — Open decisions needing NAF sign-off

Two items from the feedback cannot be safely implemented without a NAF decision,
because they change the **data model** (not just a screen). Evidence below is
drawn from the NAF sample `New_BATCH_LIST_04_06_26.xlsx` (in `nra/inbox/`).

---

## A. Disinfestation is recorded PER BOX, not per document (comment #6)

**NAF report:** "Disinfestation report not matching the sample. Batch 28 are all
disinfested but some items appear on the list; Batch 32 is not disinfested but is
not appearing."

**Root cause (confirmed from the sample):** in the NAF batch list a single
document row carries **multiple "Disinfestation Date" columns** — one per physical
box (RAS Box 1, RAS Box 2, In-Situ boxes 1-3). Disinfestation is a property of the
**box**, and a document can span several boxes with different disinfestation
states. The Mould_Boxes sheet likewise has a per-box `Disinfested` (Yes/No) +
`Disinfestation Date`.

**Current app model:** `documents.disinfestation_date` is a **single date on the
Document** (`PendingDisinfestationReport::reportQuery()` filters
`whereNull('disinfestation_date')`). So a document whose boxes are all disinfested
still shows as "pending" if that one document-level date was never set — exactly
the Batch 28 symptom. Batch 32 (date set on the document but box not disinfested)
is the inverse.

**Decision needed:** move disinfestation tracking to the **Box** (one
`disinfestation_date` / `disinfested` per box), and redefine the report as
"boxes pending disinfestation" (or "documents with ≥1 box pending"). This touches
Box, Document, the importer, the dashboard widget and the report — so it needs
explicit sign-off before we build it.

**Done already this round:** the report now shows the **box barcode** alongside
the status (the other half of comment #6).

---

## B. Location history (Documents page)

**NAF report:** "I need to quickly see the location of the document. It currently
shows the legacy location. We have no history of locations, just the current
location."

**Current app model:** a Document has a single `location_id` (current location)
plus legacy `nra_location` / `museum_location` text fields. There is a
`BoxMovement` model that logs box moves, but **no document-level location
history**.

**Decision needed:** do we want a full append-only `location_history` (every time
a document's box/location changes, write a row with from/to/when/who), or is the
existing `BoxMovement` log (location follows the box) sufficient if surfaced on
the Document view page? The first is a new table + observer; the second is a
read-only panel reusing existing data. Recommend the second as a first step.

---

## Not blocking (already shipped this round)
- Notary/Creator column + notary-identifier filter on Documents.
- Box barcode column in the disinfestation report.
- `part_number` in the mass-import (DocumentImporter).
- "Upload a Excel/CSV file" on the import step.
- Box-number uniqueness within a batch is already enforced at the form + importer
  level (a DB-level unique is intentionally NOT added: the batch list is
  document-level, so 19k+ rows legitimately repeat a box number, and Box uses soft
  deletes — a hard constraint would reject valid data and MySQL has no partial
  unique index).
