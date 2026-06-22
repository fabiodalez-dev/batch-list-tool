# Feedback 1 — Importation redesign (Block B)

Status: **DRAFT for NAF sign-off**. Nothing in here is implemented yet — it
answers the open questions raised in `Batch List Feedback 1.docx` (Importation
section + comments #0, #1, #11) so we can agree the spreadsheet shape and the
import behaviour before building it.

Block A (page amendments + the 13 verification-comment bugs) is being shipped
separately on branch `feat/feedback1-naf-reverification`. Per comment #1 we do
A first, then this.

---

## 1. Two import modes — recommendation

| Mode | When | Approach |
|---|---|---|
| **New Accessions** (ongoing) | A notary gives us new documents | **Bottom-up**: one row = one document; Batch/Box/Accession/Authority are auto-created if missing, linked if they already exist. A `Wave C` bottom-up importer already exists (`AccessionRowImporter`) and is the basis for this. |
| **Mass import** (one-off, current batch list) | First big migration of the existing batch list | Keep the current per-entity templates (Batch, Box, Document, Authority…) **but** extend them to carry every field incl. Flags. |

**Recommendation (answers comment at "For Mass Importation"):** do NOT force the
current mass migration through the bottom-up path. The legacy batch list is
already structured per-entity; a bottom-up re-derivation risks mismatches. Use
bottom-up only for the ongoing *New Accessions* flow. Both write to the same
tables, so nothing is lost.

---

## 2. New Accessions spreadsheet — column contract

One row per document. Columns and the entity each populates:

**Batch** (create if `Batch No` unseen, else link)
- `Batch No` — integer, unique; error if it already exists with conflicting data.
- `Accession No` — may be multiple (see §3); links Batch ↔ Accession (N:N).
- `Type` — maps to **Accession Type** (renamed from "Batch Type"); error if not a known type.
- `Description` — auto from Accession Title(s), comma-joined; editable later.
- `Repository` — code (e.g. `NRA`); error if unknown.

**Box** (create if `Batch+Box` combination unseen)
- `Box No` — unique within the Batch.
- `Box Barcode` — globally unique, **never null** (see §5).
- `Status`, `Seal Number`, `Location` — Box-level (Seal Number + Location added per Boxes-page feedback).

**Authority** (create if `Identifier` unseen)
- `Identifier` — unique; error if Identifier/Name/Surname/Practice mismatch an existing Authority.
- `Name`, `Surname` — combinations may repeat across identifiers (two "Paolo Vassallo").
- `Entity Type` — default `Notary` (changed manually later).
- Practice dates — left blank at import.

**Document** (always created)
- `Document Type` — error if blank/unknown.
- `Series` — error if blank/unknown.
- `Repository` — code; error if unknown.
- `Volume No` (renamed from `Volume label` / `Volume`).
- `Part Number` — **new field** to be added.
- `Practice` — error if not an existing option (create first).
- `Dates`, `Deeds`, `Notes`.
- `Authorities` — the actual Identifier(s); multiple allowed (see §3).
- `Batch No`, `Box No` (renamed from `Current Box`), `Accession No`.
- Left **blank** at import: `Catalogue Identifier`, `Location`, `Disinfestation Date`,
  `Cataloguing extras`, `Legacy Box history`, `Legacy Barcodes`.
- `Custody Status` — default `In Box`.
- `Current Box Type` — error if reference code unknown.

If a referenced Batch/Box/Accession/Authority was not created in an earlier row,
the row errors (the cascade should have created it first).

---

## 3. Multi-value Authorities — proposed format (answers the explicit NAF question)

A document may have multiple authorities, and name/surname are not unique. Use
**parallel `;`-delimited lists**, keyed positionally:

```
Identifier      = R-001 ; R-014
Name            = Paolo ; Giuseppe
Surname         = Vassallo ; Borg
```

Rules:
- `Identifier` is the source of truth for matching. `Name`/`Surname` are validated
  against the matched Identifier; a mismatch errors the row (catches typos).
- Same count across the three columns, else row error.
- A single authority uses no delimiter (back-compatible).

This keeps the Identifier authoritative while letting staff sanity-check names.

---

## 4. Field renames (UI + import headers)

| Old | New | Note |
|---|---|---|
| Batch Type | **Accession Type** | UI label already done in Block A; importer header alias to add. |
| Volume / Volume label | **Volume No** | |
| Current Box | **Box** | column header for the Box No on the Document. |

"Identifier" is the agreed general term for code-like fields (per the Location
feedback). New lookups should expose a visible auto-generated `Identifier`.

---

## 5. Box barcode integrity (New Box feedback)

- Barcode **never null** on import or via the form.
- Barcode **globally unique** across all boxes (already enforced by
  `boxes_barcode_unique`); import must give a clear per-row error on a duplicate
  rather than a SQL failure.

---

## 6. Open items deferred by the NAF themselves (track, don't build yet)

- `Cataloguing extras`, `Legacy Box history`, `Legacy Barcodes` — "feedback later".
- Flag types vs colour coding — "review later" (comment on Flag types).
- Report templates (comment #11) — confirm which standard reports are needed; the
  `ReportTemplate` resource exists, so we can pre-build the agreed set.
- Mass-import template update for Flags — pending the decision in §1.

---

## 7. Suggested build order once signed off

1. Add `Part Number` field + migration on documents.
2. Importer header aliases for the renames (Accession Type, Volume No, Box).
3. Extend `AccessionRowImporter` to the full column contract in §2, incl. the
   `;`-delimited multi-authority parser in §3.
4. Box importer: add `Seal Number`, `Location`; enforce non-null unique barcode
   with a clear per-row error.
5. Per-field error messages surfaced in the Import Status failed-rows report.
