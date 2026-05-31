<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Pages\Account\PreferencesPage;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\TwoFactorEnrolment;
use App\Filament\Pages\FieldPermissionMatrix;
use App\Filament\Pages\ImportWizard;
use App\Filament\Pages\Reports;
use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\FlagsByTypeReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Filament\Pages\Settings\AuditSettingsPage;
use App\Filament\Pages\Settings\BackupHealthPage;
use App\Filament\Pages\Settings\BrandingPage;
use App\Filament\Pages\TwoFactorProfile;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Filament\Resources\AuditResource\Pages\ListAudits;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\BackupDestinationResource\Pages\ListBackupDestinations;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Filament\Resources\BoxMovementResource\Pages\ListBoxMovements;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentFlagResource\Pages\ListDocumentFlags;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use App\Filament\Resources\ImportProfileResource\Pages\ListImportProfiles;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Resources\Lookups\BarcodeStatusResource\Pages\ListBarcodeStatuses;
use App\Filament\Resources\Lookups\BatchTypeResource\Pages\ListBatchTypes;
use App\Filament\Resources\Lookups\BoxTypeResource\Pages\ListBoxTypes;
use App\Filament\Resources\Lookups\CurrentBoxTypeResource\Pages\ListCurrentBoxTypes;
use App\Filament\Resources\Lookups\DigitisationStatusResource\Pages\ListDigitisationStatuses;
use App\Filament\Resources\Lookups\FlagTypeResource\Pages\ListFlagTypes;
use App\Filament\Resources\PracticeResource\Pages\ListPractices;
use App\Filament\Resources\ReportTemplateResource\Pages\ListReportTemplates;
use App\Filament\Resources\RepositoryResource\Pages\ListRepositories;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\VolumeResource\Pages\ListVolumes;

/**
 * Central registry of per-page "About this page" explanations.
 *
 * Each entry explains what a Filament page is for, in the language of the NRA
 * Batch List Tool requirements (RFQ-2026-06 Appendix 1), so an operator
 * (Administrator / Reading Room / General) understands the page's role in the
 * acquisition -> storage -> disinfestation -> cataloguing -> migration workflow.
 *
 * Keyed by the fully-qualified page class. The ExplainsPage trait renders the
 * matching entry as a collapsible card via the page subheading. Pages with no
 * entry simply show no card.
 */
class PagePurposes
{
    /**
     * @return array<class-string, array{body: string, refs: string}>
     */
    public static function all(): array
    {
        return [
            ListDocuments::class => [
                'body' => "The Documents register is the heart of the Batch List — one row per notarial document (a register copy or loose sheet). Track each document through its lifecycle: acquisition → storage → disinfestation → cataloguing → migration to the NRA.\n\nSearch by current AND past identifiers and volume numbers, filter (e.g. uncatalogued documents, missing disinfestation dates), and move a document between boxes through the audited \"Move to box\" action. A document cannot be marked PERM OUT without a disinfestation date.",
                'refs' => 'RFQ §3.1.1, §3.1.6, §3.2.1 · Appendix 1 rule 2 · Glossary: Document',
            ],
            PreferencesPage::class => [
                'body' => 'Your personal Preferences page. It does not touch any archival record — it tailors how the Batch List Tool behaves for you, the signed-in user, so day-to-day data entry and review stay quick and comfortable.

Three settings are saved against your own account: the default table page size (10/25/50/100 rows shown per page in every register), the interface Language (English or Italiano, blank = system default), and the Display timezone used when dates and times are rendered. None of these change the underlying data, only your view of it.

Changes take effect after you press “Save changes”. The default-repository choice lives on the Profile page, not here, to avoid duplication.',
                'refs' => 'RFQ §3.3 (User Roles) · §3.4.3 (Usability — quick data entry and review) · §3.5 (Multi-Repository)',
            ],
            EditProfile::class => [
                'body' => 'Your account Profile page. Here you maintain your own identity in the system — name and email — and change your password (the current password is required to confirm any sensitive change). Because every edit, box move and disinfestation entry is written to the audit trail against your user, keeping this record accurate is what makes “who changed what” trustworthy.

This page also holds your Default repository: the archive that is pre-selected when you open the panel. The list is restricted to repositories you are actually assigned to, and the server re-checks that constraint on save — you cannot set a repository you do not belong to. This keeps each repository\'s boxes, locations and records properly separated.

Profile changes affect only your account, never the archival data itself.',
                'refs' => 'RFQ §3.3 (User Roles) · §3.1.5 (Full audit trail — User) · §3.5 (Multi-Repository / provenance & data separation) · §3.4.3 (Usability)',
            ],
            TwoFactorEnrolment::class => [
                'body' => 'The two-factor (2FA) enrolment flow for your own account. It strengthens sign-in with a time-based one-time code from an authenticator app (1Password, Authy, Google Authenticator, etc.), on top of your password — strongly recommended for Administrator and ReadingRoom users who can write or manage permissions.

Click “Enable 2FA” to generate a secret; the page renders a QR code (and a manual setup key) entirely locally as inline SVG — no external requests. Scan it, then type the six-digit code to confirm enrolment, which sets your two_factor_confirmed_at and surfaces one-time recovery codes — save them, they are the only way back in if you lose your device. “Disable 2FA” wipes the secret and recovery codes.

This is self-service: it secures access to the auditable register but changes no archival record. It complements the per-user roles (Administrator / ReadingRoom / General) and field-level permissions that govern what each operator may do.',
                'refs' => 'RFQ §3.3 (User Roles) · §3.1.4 (Field-level permissions) · §3.1.5 (Full audit trail) · §3.4.3 (Usability)',
            ],
            FieldPermissionMatrix::class => [
                'body' => 'This page is the field-level permission matrix: the screen where an Administrator decides, for each resource and each individual field, whether a role may read it, write it, or has it hidden entirely. Roles follow the RFQ model — Administrator (super_admin / admin), ReadingRoom (editor) and General (viewer). Toggles are shown per field per role; write implies read, and "hidden" wins over both. The super_admin column is intentionally not editable: it is hard-wired to full read+write access and is never hidden.

It supports the controlled-editing requirement that protects sensitive metadata throughout the NRA workflow — for example keeping disinfestation dates, barcode status or box history editable by the Reading Room but read-only or hidden for General users, so cataloguing and migration data is not changed by accident.

The matrix is layered: config/field_permissions.php is the version-controlled baseline, and what you save here is a runtime override (a FieldPermissionOverride row, itself audited) that takes precedence — no deploy needed. "Save changes" persists one override per resource/field and the new matrix is live for every user on their next page load; "Reset to config defaults" drops every override and reverts to the baseline.',
                'refs' => 'RFQ §3.1.4 (field-level permissions: read/write/hidden) · §3.3 / §3.3.1 (user roles: Administrator / ReadingRoom / General) · §2.1 (controlled editing, field-level permissions) · §3.1.1 / Appendix 2 (per-field metadata)',
            ],
            ImportWizard::class => [
                'body' => 'This is the guided bulk-import workflow that loads legacy and new data into the archive from CSV or Excel files. It is the primary entry point for onboarding a notary\'s records and for the periodic Notary Accessions described in the RFQ, where the team packs a notary\'s collection and transfers it into the system before disinfestation, cataloguing and migration. Imports run in dependency order: Series, then Authorities (notaries), Locations, Batches, Boxes, and finally Documents (the main entity) - import the parents first or child rows that reference a missing parent will be rejected.

The wizard walks through six steps: pick what you are importing, download a template with the exact expected headers, upload your filled .xlsx/.csv (up to 50 MB), preview the first ten rows and map each spreadsheet column to an importer field (required fields must be mapped before you can continue), then run a validation dry-run over every row. The validation pass writes nothing to the database - it reports how many rows pass, how many would fail, and the per-row field-level errors, so you can fix the spreadsheet before committing. This satisfies the RFQ rule that bulk import must validate data, flag invalid records and produce an error/exception report before records are committed. You cannot start the import if zero rows are valid.

On commit the rows are queued as a background job batch. Duplicate handling is controlled by the "skip duplicates" option, and foreign keys (Series, Authority, Batch, Box) are resolved by name during the run. Rows that still fail at write time (FK not found, DB-unique conflicts) are captured and can be retrieved via the "Download failed rows (CSV)" action, which exports the original row data plus the validation error. You can also save your column mapping as a reusable Import Profile from the final step. Access is restricted to admin and super_admin roles because an import affects the whole repository.',
                'refs' => 'RFQ §3.1.3 (bulk import CSV/Excel: validate, identify duplicates/invalid, error/exception report before commit) · §2.1 (support bulk import of new accessions) · §1.b / Glossary: Accession, Notary Accessions · Glossary: Batch (1-29 Main Collection, 30+ Accessions, 50 Wills)',
            ],
            Reports::class => [
                'body' => 'The Reports hub is the entry point to the NRA\'s reporting and stock-take tooling. It surfaces the six canned reports promised in the bid — documents by batch, by creator/notary, by series, pending disinfestation, box movement history, and flags by type — each with a live count (cached briefly) so you can see the size of the dataset before opening a report.

This page exists because the legacy spreadsheet made it hard to generate reports for disinfestation cycles, migration planning, and stock-takes. From here you reach the detailed report pages, where you can select fields, apply filters (e.g. uncatalogued documents or missing disinfestation dates), export results, and save report templates for reuse.

Below the report cards the page also lists the saved report templates you can access — either templates you own or ones shared within your repository — so a previously configured view is one click away. Visibility is governed by the view_any_report permission and, for non-admin users, is automatically restricted to your own repository\'s data.',
                'refs' => 'RFQ §3.2.1, §3.2.2 · §2.3 (reporting for disinfestation/migration/stock-takes) · §3.5.1 (multi-repository separation)',
            ],
            BoxMovementHistoryReport::class => [
                'body' => 'This report is a chronological log of box-to-box movements, preserving the provenance and movement history the legacy spreadsheet tracked with repeated columns. Each row shows the movement date, the document, the from/to boxes, and the reason — giving operators an auditable trail of how documents have travelled between boxes, locations, and workspaces over time.

You can filter the log by date range and target box, export the results to CSV / Excel / PDF, and save the configured view as a report template. Movements without a recorded target box (legacy data) still appear so no audit-trail visibility is lost. Each movement carries its own repository id and is scoped per-repository, so the report is tenant-correct for non-admin users while admins see across repositories.',
                'refs' => 'RFQ §3.1.6 (record/track box & document movement, preserve provenance) · §3.2.2 (filters, export, save templates) · §3.5.1 (per-repository separation)',
            ],
            DocumentsByBatchReport::class => [
                'body' => 'This report counts documents grouped by their batch number, so you can see how the collection is distributed across batches at a glance. Documents not yet assigned to a batch are gathered into a single "(unassigned)" row, exposing un-batched backlog. The batch dimension is central to the NRA\'s logistics: batches 1–29 are the Main Collection, 30+ are Notary Accessions, and 50 is reserved for wills (33/34/36 are reserved or unused per the validation rules).

It is a full reporting surface, not just a table: filter by batch type (Main collection / Notary accession), by document date / created / updated / disinfestation date ranges, by repository, series, creators, document type and current barcode status (IN / OUT / PERM OUT), and by workflow ternaries — has open flags, uncatalogued, Torre, and currently in disinfestation. Results can be exported to CSV, Excel, or PDF, and the current filter/column/sort state can be saved as a report template for reuse.',
                'refs' => 'RFQ §3.2.2 (select fields, apply filters, export, save templates) · §3.2.1 (search by batch) · Glossary: Batch · Appendix 1 rule 1 (batches 33/34/36/50)',
            ],
            DocumentsByCreatorReport::class => [
                'body' => 'This report counts documents grouped by their creator — in this collection, normally the notary (Authority). Each row shows the creator\'s code, surname and given names with a document count. Because a document can be attributed to more than one creator (a many-to-many relation), a document with two creators is counted under each of them; the report is about per-creator workload, not a globally de-duplicated total.

Use it to profile a notary\'s holdings and drive migration/cataloguing planning. You can show only authorities that actually have documents, and constrain the underlying document set by date ranges (document dates, created, updated, disinfestation), repository, series, document type, current barcode status, and workflow ternaries (open flags, uncatalogued, currently in disinfestation). Results export to CSV / Excel / PDF, and the configured view can be saved as a report template. Creators are global reference data, but the counts only include documents visible to your repository.',
                'refs' => 'RFQ §3.2.2 (select fields, filters, export, save templates) · §3.2.1 (search by creator) · Glossary: Creator (notary)',
            ],
            DocumentsBySeriesReport::class => [
                'body' => 'This report counts documents grouped by their series code (R = Register Copies, REG = Registers Private Practice, RWL = Registers Private Practice Public Wills, O = Originals/Minutari, and so on), showing the series code and title alongside the document count. It lets you understand the composition of the collection by document type/series — useful for cataloguing and migration prioritisation.

Like the other report pages it supports selecting the reporting dimension, applying filters (date ranges, repository, series, document type, barcode status, and workflow ternaries such as uncatalogued or pending disinfestation), exporting to CSV / Excel / PDF, and saving the current configuration as a reusable report template. Counts respect your repository scope automatically.',
                'refs' => 'RFQ §3.2.2 (select fields, filters, export, save templates) · §3.2.1 (search by series) · Glossary: Document, Volume',
            ],
            FlagsByTypeReport::class => [
                'body' => 'This report replaces the legacy spreadsheet\'s colour-coding with structured issue flags, grouped by flag type so you can see — per type and severity — how many flags are still open or acknowledged versus resolved or dismissed, plus when each type was most recently raised. It turns ad-hoc coloured cells into searchable, filterable, reportable alerts that users can resolve.

The filters intentionally mirror the standalone flags dashboard so you don\'t have to learn a new vocabulary moving between the two. Use this report to triage outstanding data-quality and conservation issues, drive remediation, and track resolution over time. Results export to CSV and PDF, and the configured view can be saved as a report template. Flags carry a denormalised repository id, so the report is automatically scoped to your repository for non-admin users.',
                'refs' => 'RFQ §3.1.12 (replace colour-coding with searchable/filterable/reportable flags) · Appendix 2 (xviii) · §3.2.2 (export, save templates)',
            ],
            PendingDisinfestationReport::class => [
                'body' => 'This report surfaces the actionable disinfestation backlog: every document whose disinfestation date is still empty and whose current box is not PERM OUT (PERM OUT documents are off the floor and don\'t need fumigation). Disinfestation is a mandatory pest-control treatment in the acquisition → storage → disinfestation → cataloguing → migration workflow — documents cannot migrate back to the NRA, and cannot be marked PERM OUT, until it is recorded.

Unlike the dashboard\'s top-10 widget, this page renders the full list, sortable and filterable (identifier, type, series, batch and the standard report filters), and exportable to CSV / Excel / PDF. The current view can be saved as a report template. The "missing disinfestation dates" filter is the concrete example called out in the RFQ reporting requirement. Repository scoping is applied automatically for non-admin users.',
                'refs' => 'RFQ §3.2.2 (apply filters e.g. missing disinfestation dates, export, save templates) · Appendix 1 rule 2 (PERM OUT requires disinfestation date) · Glossary: Disinfestation',
            ],
            AuditSettingsPage::class => [
                'body' => 'This page configures the audit trail itself. A super-administrator can turn audit writing on or off (when off, no new audit records are written but every existing record is preserved) and set the retention threshold in days (0 keeps records indefinitely). It is the control panel behind the read-only Audit log, governing how the §3.1.5 "who changed what, when" history is captured and how long it is kept.

Keeping a reliable, long-lived audit trail is essential to the NRA workflow, because every box movement, barcode status change and disinfestation/cataloguing edit must remain reconstructable for compliance long after migration. The retention threshold lets the institution balance that obligation against storage growth.

Access is restricted to super_admin only — admins and viewers receive a 403 on mount. Settings are persisted via spatie/laravel-settings (group: audit) and applied at runtime (mirrored into config(\'audit.enabled\') on boot), so changes take effect on the next request.',
                'refs' => 'RFQ §3.1.5 (full audit trail: old value, new value, user, timestamp) · §3.3.1 (Administrator: full access incl. permissions management) · §3.4.2 (backups / data retention) · §3.5.1 (audit history preserved per repository)',
            ],
            BackupHealthPage::class => [
                'body' => 'The Backup Center is the operational hub for the RFQ daily-backup requirement. It shows a read-only health summary (status of the last backup file, live database connectivity, and free/used disk space with ok/warning/danger thresholds) so an administrator can confirm at a glance that backups are running.

From here you can list every .zip archive across all configured destination disks, download or delete an individual archive, and trigger an on-demand backup — full, database-only or files-only — which is queued in the background and recorded in the run-history table. The retention policy (how many daily, weekly and monthly backups to keep on each destination) is set and saved on this page. Connection details for off-site targets are managed separately under Backup destinations, linked from the header.

Restore overwrites the live database from a chosen archive and is therefore super_admin only: it requires ticking an acknowledgement and re-typing the exact current database name, and a fresh safety snapshot is taken automatically before anything is overwritten. All file paths are guarded against directory traversal.',
                'refs' => 'RFQ §3.4.2 (daily automated backups) · §3.4.1 (scalable / performance) · §3.3 (Administrator / super_admin gating)',
            ],
            BrandingPage::class => [
                'body' => 'Customise the look of the panel without touching code: set the application name (shown in the header and browser tab), upload a logo, set its display height, and remove the logo to fall back to the brand name as text. Changes are saved via settings and applied on the next page load.

This supports the multi-archive nature of the system — a repository or archive that adopts the tool can present it under its own identity. The uploaded logo is served locally from the public disk (no external CDN), in line with the project\'s local-assets policy. Restricted to Administrators (super_admin / admin); other roles are refused.',
                'refs' => 'RFQ §3.5 (multi-repository / multi-archive adoption) · §3.3 (Administrator-only) · §3.4.3 (usability)',
            ],
            TwoFactorProfile::class => [
                'body' => 'The two-factor authentication management page for your own account. From here you enable a TOTP second factor, confirm enrolment with a six-digit code, regenerate recovery codes, or disable 2FA (password-confirmed). Recovery codes are shown once and never persisted to the session. Strongly recommended for Administrator and super_admin users.

This is a per-user security page — it hardens access to the auditable register but does not modify any archival record. Enabling/confirming/disabling 2FA writes an audit row via the LogTwoFactorChange listener, keeping the “who changed what” trail complete.',
                'refs' => 'RFQ §3.3 (User Roles) · §3.1.4 (Field-level permissions) · §3.1.5 (Full audit trail) · §3.4.3 (Usability)',
            ],
            ListAccessions::class => [
                'body' => 'The Notary Accessions register records each new acquisition of documents from a notary. An accession is the formal acquisition event at the start of the workflow (acquisition → storage → disinfestation → cataloguing → migration): the NRA team liaises with the notary, the documents are received, and an accession ID is assigned.

Each row carries a code, an optional Notary Accession Number, an accession date, and links to the originating Authority (the notary/creator), the Batch the accession was packed into, and the repository. Records with no accession value belong to the historical Main Collection; going forward every new acquisition is given an accession id (e.g. a Tabone Accession). The page can be filtered by authority, batch, repository, and whether a Notary Accession Number is present.

For Notary Accessions a batch is allocated per accession (in the 30+ range), and any wills in the accession are packed into Batch 50 immediately.',
                'refs' => 'RFQ §3.1.1, §3.1.3 · Appendix 2: RAS Box (Accession) · Glossary: Accession',
            ],
            ListAudits::class => [
                'body' => 'This is the audit log: a read-only, searchable record of every change made anywhere in the Batch List Tool. Each row answers "who changed what, when" — the When (timestamp), the Who (the user, or a dash for system actions), the event (created / updated / deleted / restored, plus impersonation start/end), the Model and Record ID that was touched, and optionally the originating IP address and user agent. Opening a row (View) shows the field-by-field old value and new value for that change.

In the NRA workflow the audit trail is the accountability backbone across the whole lifecycle — acquisition, storage, disinfestation, cataloguing and migration. Because the legacy spreadsheet had no way to track who edited a cell, every box movement, barcode status change (IN / OUT / PERM OUT), disinfestation date and metadata edit is now captured automatically here so the institution can reconstruct the provenance of any decision.

The log is write-only: there is no Create, Edit or Delete and no bulk delete — audit records are produced automatically by the model observers and cannot be altered from the panel, which is what makes them trustworthy. Use the Event, Model and date-range (From / To) filters to narrow the list, and sort by When (newest first by default) when investigating a specific incident.',
                'refs' => 'RFQ §3.1.5 (full audit trail: old value, new value, user, timestamp) · §2.1 (auditable database) · §2.3 (legacy: no audit trail) · §3.5.1 (audit history per repository)',
            ],
            ListAuthorities::class => [
                'body' => 'The Creators register (Authorities) holds the authors of the documents — in this collection normally the notaries. Each creator is the provenance anchor a document is attributed to, so this is reference data that must exist before documents can be linked to it.

Each row records the creator\'s identifier (e.g. R1) and alternative / MS identifier (e.g. MS511), surname and given names, entity type, practice date range (start/end years) and NTG date. The list supports searching by identifier or surname and filtering by entity type and practice period.

The register can be populated individually, or bulk-imported from the Authorities sample workbook (around 808 rows) using the Excel/CSV importer, with a downloadable template whose headers match the sample file verbatim. Identifiers are unique, so re-importing updates rather than duplicates a creator.',
                'refs' => 'RFQ §3.1.1, §3.1.3, §3.1.11 · Appendix 2: Catalogue Identifier (Creator) · Glossary: Creator, Identifier',
            ],
            ListBackupDestinations::class => [
                'body' => 'Configure where the daily automated backups are sent. Each destination has a friendly name, a storage type (Local disk, FTP, SFTP/SSH or S3-compatible) and the matching connection fields (host, port, credentials, root path). Exactly one destination can be the default — the primary target highlighted in the Backup Center.

This underpins the RFQ non-functional requirement for daily automated backups: backups can be written off-site (FTP/SFTP/S3) so a failure of this server does not lose the notarial register data. Secret fields (password, passphrase, secret key) are write-only — they are stored encrypted, never sent back to the browser on edit, and only overwritten when you actually type a new value; leave them blank to keep the current credential. Inactive destinations are ignored by backups but kept for later.

This page only defines the targets. To check backup health, list existing archives, run a backup on demand or restore, use the Backup & Health page (the Backup Center). Restricted to Administrators.',
                'refs' => 'RFQ §3.4.2 (daily automated backups) · §3.4.1 (scalable) · §3.3 (Administrator-only)',
            ],
            ListBatches::class => [
                'body' => 'The Batches register lists the logical groupings used to pack boxes together. A batch is a set of boxes packed in one go (each main-collection batch held roughly 250 boxes). Batch numbering classifies the collection: 1–29 are the Main Collection and 30+ are Notary Accessions.

Each batch row records its number, type, description, repository and active flag, and links through to its boxes and documents. The "View boxes" action jumps straight to the boxes packed in that batch, and batches can be filtered by number, type and active state.

Reserved-number rules are enforced: batch numbers 33, 34 and 36 cannot be used (34 and 36 are permanently unused; 33 is reserved for old MAV boxes), and Batch 50 is reserved exclusively for wills — wills found in original boxes are transferred to Batch 50, and for Notary Accessions wills are packed into Batch 50 immediately. New batches can be created individually or bulk-imported from Excel/CSV, where reserved numbers are rejected with a per-row validation message.',
                'refs' => 'RFQ §3.1.1, §3.1.6 · Appendix 1 rule 1 · Appendix 2: RAS Box (Batch) · Glossary: Batch',
            ],
            ListBoxMovements::class => [
                'body' => 'The Box Movements register is the audited movement and provenance log: one row per recorded transfer of a document between boxes. Each entry links a Document to a From box and a To box (both server-side autocomplete Selects, because the boxes table runs into the hundreds), with a movement date, a free-text reason, and the acting user captured automatically.

In the NRA workflow this is the trail behind every storage change — a document leaving its original RAS box for an In Situ box, moving to a conservation or museum location, or returning. Rather than overwriting a document\'s current box silently, those changes leave a permanent record here so the full custody chain (acquisition to migration) can be reconstructed and reported on.

This page is mostly a read/audit view: most movements are created through the Document register\'s "Move to box" action, which writes the history alongside the document\'s current-box update. Use the columns and filters here to trace where a given document or box has been, and who moved it when.',
                'refs' => 'RFQ §3.1.6 · Appendix 2 (iii) Box History, (i) RAS Box, (ii) In Situ Box · Glossary: Box, Provenance',
            ],
            ListBoxes::class => [
                'body' => 'The Boxes register tracks every physical container that holds notarial documents: RAS boxes (stored at Rent-A-Store, identified by Batch + Box number plus a barcode) and In Situ boxes (NRA, MAV, STVC types) created at the NRA when documents are fished out of RAS boxes. This is the storage backbone of the acquisition → storage → disinfestation → cataloguing → migration workflow.

Each box carries a type, batch, barcode and barcode status (IN / OUT / PERM OUT), an optional seal number (recorded for Batch 50 wills boxes), a current NRA location, and a destroyed flag. Box and barcode changes are versioned: the page exposes the box barcode history (old/new barcode, status from/to, reason, timestamp) and the seal number history, satisfying the requirement to preserve movement and barcode provenance.

Key rules enforced here: In Situ boxes (NRA/MAV/STVC) must reference a parent RAS box unless explicitly marked as provenance-unknown; MAV and STVC are legacy types and are flagged as such; a box/document cannot be set PERM OUT without a disinfestation date; and once every document in a box is catalogued the box may be marked destroyed. New rows can be created individually or bulk-imported from Excel/CSV (with a downloadable template).',
                'refs' => 'RFQ §3.1.1, §3.1.6, §3.1.7, §3.1.10 · Appendix 1 rules 1, 3 · Appendix 2: RAS Box, In Situ Box, Box History, Barcodes, Box Destroyed, Current Box · Glossary: Box, RAS Box, In Situ Box, Barcode Status',
            ],
            ListDocumentFlags::class => [
                'body' => 'The Document Flags register is the structured replacement for the legacy spreadsheet colour-coding. Each row attaches an issue flag to a single Document with a category (type), a severity (info / warning / critical), and a workflow status (open, acknowledged, resolved, dismissed), plus a full audit trail of who flagged it and when, and who resolved it with what notes.

The flag types map the old row colours onto searchable, reportable categories: Pink (entry issue), Brown (barcode issue), Orange (quarterly stock-take location check), Grey (brought on-site but not yet disinfested), Red (mould treatment), and Yellow (sorted fragments), alongside operational flags such as duplicate suspect, missing data, wrongly catalogued, and disinfestation overdue. Because they are real records, flags can be filtered, counted, and chased to closure instead of being a colour a human has to notice.

Open and acknowledged flags are the actionable queue surfaced to operators; resolved and dismissed flags are archived. The repository is mirrored automatically from the parent document, so a flag always belongs to the same tenant as the document it concerns, and the type is validated against the active rows of the editable flag-types lookup.',
                'refs' => 'RFQ §3.1.12, §3.2.1 · Appendix 2 (xviii) Colour Coding · Glossary: Document Flag',
            ],
            ListDocumentTypes::class => [
                'body' => 'This page manages the document-type controlled vocabulary used when cataloguing each notarial item. A document in the NRA collection is a single unit — typically a register copy of a notarial deed, or a loose sheet — and this list standardises how that kind is recorded so it can be searched, filtered, and reported on consistently.

Each entry has a unique name, an optional description, and an active flag. The list is intentionally append-and-deactivate: rather than hard-deleting a value (documents in production may still reference it by name), use the \'Deactivate selected\' bulk action to hide it from new Document forms while keeping historical documents that reference it readable.

Maintaining accurate document types supports the cataloguing stage of the workflow and the reporting requirement to search records by document type.',
                'refs' => 'RFQ §3.1.11, §3.2.1 · Appendix 1 rule 5 (vocabularies managed in discussion with NAF) · Glossary: Document',
            ],
            ListImportProfiles::class => [
                'body' => 'This page lists the saved column-mapping profiles used by the Import Wizard. A profile captures, per entity type (Series, Authorities, Batches, Boxes, Documents), how the headers in your spreadsheet map onto the system\'s importer fields, so a recurring bulk import - for example each new Notary Accession that arrives in the same spreadsheet layout - can be replayed without re-mapping every column by hand.

Profiles are created only by ticking "Save as profile" on the final step of the Import Wizard, which is why there is no "Create" button here: authoring a blank mapping would not match any real file. From this list you can view a profile (including its read-only column map), rename it, share it with other users in your repository, or delete it. The table shows owner, how many times each profile has been used and when it was last used, and you only see profiles you own or that have been shared in your repository. To change a mapping, re-run the wizard against a real file and save a fresh profile.

Profiles are a convenience layer on top of the import pipeline - they do not bypass any validation. Every import started from a profile still runs the wizard\'s row-by-row dry-run and produces the same error/exception report before any records are committed.',
                'refs' => 'RFQ §3.1.3 (bulk import CSV/Excel with validation and pre-commit error report) · §2.1 (support bulk import of new accessions) · §1.b / Glossary: Accession (Notary Accessions)',
            ],
            ListLocations::class => [
                'body' => 'The Locations register holds the configurable storage hierarchy where boxes and documents physically live at the NRA. Each location has a name, a type (repository, room, work area, shelf, museum, showcase, conservation, temp holding, or other), an optional parent (building a tree up to six levels deep), an optional short code, a sort order among siblings, and a repository scope — leave the repository blank for a GLOBAL location visible to every tenant.

This directly serves the NRA Location and Museum Location concepts from the requirements: a whole box or a single document records the NRA location where it currently sits (e.g. Archive 1, Cataloguing, Museum), and documents whose NRA Location is Museum additionally carry a Showcase location — modelled here as museum/showcase type nodes. The tree feeds the location filters on Box and Document and the icon/badge shown there.

Key rules to know: the parent list rejects cycles (a node cannot be its own ancestor) and enforces the maximum depth; codes must be unique within the same repository; sort order only orders siblings under the same parent. Use this page to set up and maintain the hierarchy before assigning boxes/documents to locations; bulk setup is available via the Excel/CSV import and the downloadable template.',
                'refs' => 'RFQ §3.1.6, §3.1.9, §3.2.1 · Appendix 2 (v) NRA Location, (vi) Museum Location · Glossary: Location',
            ],
            ListBarcodeStatuses::class => [
                'body' => 'This is the controlled vocabulary of barcode statuses used throughout the NRA Batch List. RAS (Rent-A-Store) assigns every box a barcode, and a barcode can carry one of three statuses: IN (the box is in the storage facility), OUT (the box is temporarily out and will return under the same barcode), and PERM OUT (the box has been permanently taken out of RAS and should now have an NRA location). These values drive barcode history and the location/disinfestation validation across the system.

Use this page to manage that list: each entry has a machine code, a display label, a sort order (drag rows to reorder), and an active flag. Codes are stored verbatim on document/box records, so renaming a code here does NOT rewrite existing rows; deactivate rather than rename or delete a value that is already in use. Deactivating hides a status from new pickers app-wide while leaving historical records readable. Editing is restricted to Administrators (admin / super_admin).

The PERM OUT status carries a business rule enforced elsewhere: a document cannot be marked PERM OUT unless it has a disinfestation date. Keep these three canonical statuses intact so that barcode tracking and the migration workflow (acquisition → storage → disinfestation → cataloguing → migration) stay consistent with the RAS source data.',
                'refs' => 'RFQ §3.1.11, §3.1.7 · Appendix 1 rule 2 · Appendix 2: Barcodes (IN / OUT / PERM OUT) · Glossary: Barcode Status',
            ],
            ListBatchTypes::class => [
                'body' => 'This page manages the batch-type controlled vocabulary. A batch is a group of boxes packed together; batch numbers 1–29 are the Main Collection, 30+ are Notary Accessions, and batch 50 is reserved exclusively for wills, while 33 (old MAV), 34 and 36 carry special handling. This lookup classifies batches by type so those rules and reports stay consistent.

Each entry has a machine code, a display label, a sort order (drag to reorder), and an active flag, plus optional JSON metadata. Codes are stored on records, so renaming here does not rewrite existing rows; deactivate instead of deleting a value already in use — deactivation hides it from new records but leaves historical ones intact. Access is restricted to Administrators (admin / super_admin).

Keeping batch types curated supports the acquisition side of the workflow: when notary accessions arrive and boxes are packed, and when wills are transferred to batch 50, the batch classification feeds search, filtering, and migration-planning reports.',
                'refs' => 'RFQ §3.1.11 · Appendix 1 rule 1 · Appendix 2: RAS Box — Batch (1–29 Main Collection, 30+ Accessions, 50 Wills, 33/34/36) · Glossary: Batch',
            ],
            ListBoxTypes::class => [
                'body' => 'This page manages the box-type controlled vocabulary — the kinds of physical containers a document can live in across its life in the archive: RAS boxes (at the Rent-A-Store facility) and the In Situ box types created at the NRA (NRA, Conservation, and the legacy MAV / STVC types). It is the reference list the box and document forms read when recording where an item is stored.

Each entry has a machine code, a label, a sort order (reorderable), an active flag, and a legacy flag. The legacy flag is significant: MAV and STVC numbering systems are no longer in use, so legacy box types cannot be assigned to new records (Appendix 1 rule 4) — they exist only so historical rows remain readable. Codes are stored on records verbatim, so renaming a code does not update existing rows; prefer deactivating over deleting a value that is in use. Editing is limited to Administrators.

Within the workflow this list underpins box history and provenance: In Situ boxes (NRA / MAV / STVC) normally must reference a previous RAS box, so keeping these types accurate supports the move-tracking and migration rules.',
                'refs' => 'RFQ §3.1.11, §3.1.10, §3.1.6 · Appendix 1 rules 3–4 · Appendix 2: RAS Box, In Situ Box (NRA / Conservation / MAV / STVC) · Glossary: RAS Box, In Situ Box',
            ],
            ListCurrentBoxTypes::class => [
                'body' => 'This page manages the current-box-type controlled vocabulary — the present physical state of a document\'s storage, as opposed to the historical box-type list. It covers the box-lifecycle states described in Appendix 2 such as the actual box a document sits in, plus special states like Not in Box (temporarily on a conservation or transcription/digitisation shelf) and Mounted; No Box (framed museum items with no allocated box).

Each entry has a machine code, a label, a sort order (reorderable), an active flag, and a \'counts as\' weighting. The \'counts as\' value drives disinfestation-cycle counting: a standard box counts as 1, while an oversized container (e.g. a Big Brown Box) counts as 2, so disinfestation reports reflect real volume. Codes are stored on records verbatim — renaming does not update existing rows, so deactivate rather than delete values that are in use. Editing is limited to Administrators.

Accurate current-box types keep the storage and disinfestation stages of the workflow reliable and let users filter for items awaiting treatment or migration.',
                'refs' => 'RFQ §3.1.11, §3.1.10 · Appendix 2: In Situ Box — Not in Box, Mounted; No Box · Glossary: In Situ Box',
            ],
            ListDigitisationStatuses::class => [
                'body' => 'This page manages the digitisation-status controlled vocabulary — the values that record where each document sits in the transcription/digitisation part of the cataloguing stage (for example, items held on the Transcription and digitisation shelf before they return to a box). It standardises a field that, in the legacy spreadsheet, was tracked by ad-hoc colour-coding.

Each entry has a machine code, a display label, a sort order (drag rows to reorder), and an active flag, plus optional JSON metadata. Because codes are stored on the records themselves, renaming a code here does not rewrite existing document rows; deactivate a value rather than deleting or renaming one already in use — deactivation removes it from new pickers while keeping historical records readable. Editing is restricted to Administrators.

Keeping this list curated lets users search, filter, and report on documents by digitisation progress as part of cataloguing before migration to the NRA.',
                'refs' => 'RFQ §3.1.11, §3.1.12 · Appendix 2: In Situ Box — Not in Box (Transcription and digitisation shelf) · §2.1 (cataloguing/migration)',
            ],
            ListFlagTypes::class => [
                'body' => 'This page manages the flag-type controlled vocabulary — the structured issue flags, statuses, and alerts that replace the legacy spreadsheet\'s colour-coding (brown, pink, yellow, etc.). The RFQ requires colour conventions, which cannot scale to a database, to be turned into searchable, filterable, reportable flags that users can resolve; this list is where those flag values are defined.

Each entry has a machine code, a label, a sort order (reorderable), an active flag, and an optional legacy colour field that preserves the original spreadsheet colour for reference. Codes are stored on records, so renaming does not update existing rows — deactivate rather than delete values in use; deactivation hides a flag from new records while keeping historical ones readable. Editing is limited to Administrators (admin / super_admin).

Well-maintained flag types let staff surface and clear data-quality and processing issues throughout the acquisition → storage → disinfestation → cataloguing → migration workflow.',
                'refs' => 'RFQ §3.1.11, §3.1.12 · §2.3 (colour-coding cannot scale) · Appendix 2: Box History (colour columns)',
            ],
            ListPractices::class => [
                'body' => 'This page manages the \'practice\' controlled vocabulary — the canonical list of practice values attached to creators/notaries and their documents (for example NTG, Private Practice, or mixed). It standardises a field used in cataloguing and provenance so records can be classified and searched consistently rather than relying on free text.

Each entry has a unique name, an optional description, and an active flag. As with the other reference lists, values are not hard-deleted: use the \'Deactivate selected\' bulk action to hide a value from new Document forms while keeping historical references readable.

Keeping practice values curated supports accurate creator/notary classification across the acquisition and cataloguing stages, and feeds search and reporting by creator and practice.',
                'refs' => 'RFQ §3.1.11, §3.2.1 · Appendix 1 rule 5 · Glossary: Creator',
            ],
            ListReportTemplates::class => [
                'body' => 'This page lists the saved report templates that capture a report\'s live field selection, filters, columns and sort order, so a useful view can be recalled in one click instead of being rebuilt each time. It directly fulfils the "save report templates" requirement of the reporting feature.

Templates are not created from blank here: there is intentionally no "Create" action, because an empty template is useless. Instead you create a template from any report page via its "Save as template" action, which captures the current state. From this list you can manage the templates you own or that are shared within your repository, with repository scoping applied automatically for non-admin users.',
                'refs' => 'RFQ §3.2.2 (save report templates) · §3.5.1 (per-repository data separation)',
            ],
            ListRepositories::class => [
                'body' => 'A repository is a physical archive or site that uses the system — it may be the NRA itself or another archive adopting the tool. This page lists every repository and lets you create or edit them with a unique code, a name, an active flag and an optional description.

Multi-repository support is a core requirement: each repository keeps its own boxes, storage locations, batches, accessions, documents and assigned users, while provenance, audit history and data separation are preserved across them. The detail view surfaces per-repository counts (batches, documents, accessions, assigned users) so you can see at a glance how much of the collection each repository holds.

Repositories are the top-level container for the acquisition → storage → disinfestation → cataloguing → migration workflow: every box and document belongs to exactly one repository, and user access is scoped per repository on the Users page. Repositories are soft-deleted so historical records and audit trails are never lost.',
                'refs' => 'RFQ §3.5.1 (each repository its own boxes/locations/users; preserve provenance + data separation) · Glossary: Repository · §3.3 (per-repository roles)',
            ],
            ListSeries::class => [
                'body' => 'The Series register is the controlled vocabulary that classifies documents by type — for example R (Register Copies), REG (Registers Private Practice), RWL (Registers Private Practice Public Wills) and O (Originals / Minutari). It is reference data (around 29 rows in the sample) that documents are categorised against during cataloguing.

Each row records a unique code, a title, an optional parent series (the register supports a hierarchy, shown as a hierarchy path), an active flag, and a "wills series" flag. The list can be filtered by code, by wills-series, by active state, by top-level-only, and by parent series.

The wills-series flag matters downstream: documents in a wills series feed the rule that wills must be packed into Batch 50. Series can be created individually or bulk-imported from the Series sample workbook; when the wills-series column is not mapped the importer derives it heuristically.',
                'refs' => 'RFQ §3.1.1, §3.1.11 · Appendix 1 rule 2 · Glossary: Document (Series classification)',
            ],
            ListUsers::class => [
                'body' => 'Manage the people who use the Batch List Tool and what each of them may do. Every user is given a role that maps to the RFQ access tiers: Administrator (full access, including permissions management), Reading Room (read/write on metadata), and General (read-only). super_admin is only visible to, and grantable by, an existing super_admin — an admin can never grant or even see it.

Because the system serves more than one repository, each user is assigned to one or more repositories and a default repository; their effective role can be set per repository (the pivot role), falling back to their global role. This is what keeps data separated between archives: a user only operates within the repositories they are attached to. Built-in self-protection stops you changing your own role or deactivating your own account, so an administrator cannot accidentally lock themselves out.

Use the New user / Edit actions to set name, email, an initial password (new users are always forced to change it on first login), role, repository assignments and the active flag. Deactivating rather than deleting preserves the audit history tied to that user.',
                'refs' => 'RFQ §3.3 (Administrator / ReadingRoom / General) · §3.5.1 (multi-repository, data separation) · §3.4.3 (usability)',
            ],
            ListVolumes::class => [
                'body' => 'The Volumes register tracks bound registers and collections of deeds. A volume is a bound unit that belongs to a document and may itself span multiple boxes, so this page records the volume-level detail underneath each tracked document.

Each row links to its parent document (by identifier) and records the volume number and the covered date range (from / to). This separates the physical/bound-unit information (the volume) from the document record that moves through the acquisition → storage → disinfestation → cataloguing → migration workflow.

Volumes are normally created and maintained alongside their documents; this list gives a direct view for searching and editing volume numbers and date ranges across the collection.',
                'refs' => 'RFQ §3.1.1, §3.1.10 · Appendix 2: Current Box (volumes stored in a box) · Glossary: Volume, Document',
            ],
        ];
    }

    /**
     * @return array{body: string, refs: string}|null
     */
    public static function for(string $pageClass): ?array
    {
        return self::all()[$pageClass] ?? null;
    }
}
