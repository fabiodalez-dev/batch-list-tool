<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Field-level Permissions Matrix (RFQ §3.1.8)
|--------------------------------------------------------------------------
|
| This file is the single source of truth for *per-field, per-role* access
| inside Filament resources. It complements (and layers ON TOP of) the
| resource-level RBAC provided by spatie/laravel-permission +
| bezhansalleh/filament-shield, which only answers the question
|   "Can role X see/edit DocumentResource at all?"
| while this matrix answers
|   "Of the fields visible on DocumentResource, which can role X read/write?"
|
| ## Shape
|
| return [
|     '<resource-key>' => [
|         '_default' => [
|             'read'  => ['super_admin', 'admin', 'editor', 'viewer'],
|             'write' => ['super_admin', 'admin', 'editor', 'viewer'],
|         ],
|         '<field_name>' => [
|             'read'        => [...roles allowed to READ the field],
|             'write'       => [...roles allowed to WRITE the field],
|             'hidden_from' => [...roles for whom the form input is REMOVED],
|         ],
|     ],
| ];
|
| ## Semantics
|
| * `super_admin` is ALWAYS allowed (read + write) and NEVER hidden,
|   irrespective of what this config says — enforced in code in
|   App\Support\FieldPermissions. Defence-in-depth.
| * `_default` is the fallback for fields that are not listed explicitly.
|   The recommended default is "allow all 4 roles" — this means adding a
|   NEW $fillable column to a Model does NOT silently lock users out;
|   the operator must explicitly tighten the matrix for that field.
| * `hidden_from` is the strongest control: the form input is removed
|   from the DOM and the table column is also hidden. Use it for
|   genuinely sensitive fields (audit metadata, schemaless `extra`).
| * If a role appears in `hidden_from` but NOT in `read`, hidden wins —
|   that role cannot read the field.
| * If a field has `write` granted but `read` denied, the field is still
|   functionally read-only because the form input is hidden. We do not
|   try to enforce write-without-read; the matrix should be consistent.
|
| ## Why a config file (instead of a DB ACL table)
|
| - The matrix is small (~5 resources × ~30 fields × 4 roles).
| - It changes rarely and review/diff/audit is trivial in git.
| - No N+1 queries, no cache invalidation, no migration drift.
| - Tests can swap the whole matrix at runtime with `config(['field_permissions' => [...]])`.
|
| ## Adding a new field
|
| 1. Add the column to the Model's `$fillable`.
| 2. Add a TextInput/Select/etc to the corresponding Resource form,
|    wrapped in `self::gateField(...)`.
| 3. If the default (allow all 4 roles) is correct, you are done —
|    you do NOT need to touch this file.
| 4. Otherwise, add an explicit entry here.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Document (App\Models\Document)
    |--------------------------------------------------------------------------
    | The biggest matrix — mirrors the `$fillable` array of the Document model.
    | Most operational fields are write-restricted to editor+; tenant-binding
    | fields (repository_id) are admin-only; the schemaless `extra` bucket is
    | hidden from non-admins because it may contain provenance metadata.
    */
    'document' => [
        '_default' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],

        // Tenant binding — admins only may reassign a document to a different
        // repository. Editors can read it; viewers can read it.
        'repository_id' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],

        // Primary identifier — editors may edit, viewers read-only.
        'identifier' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],

        // Free-form notes — editors edit, viewers read-only.
        'notes' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],

        // Schemaless bucket: admin-only. May contain provenance metadata
        // or operational flags the operator does not want to expose.
        'extra' => [
            'read' => ['super_admin', 'admin'],
            'write' => ['super_admin', 'admin'],
            'hidden_from' => ['editor', 'viewer'],
        ],

        // Disinfestation gate is operational data — viewers must SEE it
        // (compliance reads) but cannot edit it.
        'disinfestation_date' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],

        // Custom JSON columns — admin-only edit, all may read.
        'custom_fields' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],
        'metadata' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authority (App\Models\Authority)
    |--------------------------------------------------------------------------
    | Notary / creator reference data. Identifier is the stable key referenced
    | by documents — restricted to admin write to prevent accidental renames
    | that would break downstream joins.
    */
    'authority' => [
        '_default' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],

        // Stable canonical key — admin only (rename has cross-table impact).
        'identifier' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],

        // Notes — all writers may edit.
        'notes' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Series (App\Models\Series)
    |--------------------------------------------------------------------------
    | Reference data, rarely modified. Editors may add new series but must not
    | toggle `is_wills_series` (which has special RFQ semantics around batch 50)
    | nor flip `is_active` (which would hide existing data from list pages).
    */
    'series' => [
        '_default' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],

        // RFQ rule #2: batch 50 is exclusively for the series flagged as
        // wills. Toggling this flag changes business validation across the
        // app — restrict to admin.
        'is_wills_series' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],

        // Disabling a series hides it from new-record Selects — admin only.
        'is_active' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch (App\Models\Batch)
    |--------------------------------------------------------------------------
    | Batches are quasi-permanent groupings — only admins may reassign tenant
    | (repository_id) or change the batch type (which gates Main vs Notary
    | Accession allocation per RFQ rule #5).
    */
    'batch' => [
        '_default' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],

        'repository_id' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],

        // RFQ rule #5: Main Collection (1-29) vs Notary Accession (30+).
        // Changing `type` changes downstream allocation rules — admin only.
        'type' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],

        // Toggling an active batch off is admin-only.
        'is_active' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Box (App\Models\Box)
    |--------------------------------------------------------------------------
    | Physical containers. `box_type` and `is_legacy` gate the RFQ rule #4
    | "no creating MAV/STVC" — restricted to admin so editors cannot
    | accidentally tag a new box as legacy.
    */
    'box' => [
        '_default' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin', 'editor'],
        ],

        // Changing box_type after creation has cross-table implications
        // (MAV/STVC ↔ RAS/IN_SITU/NRA). Admin only.
        'box_type' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],

        // Legacy flag — admin only.
        'is_legacy' => [
            'read' => ['super_admin', 'admin', 'editor', 'viewer'],
            'write' => ['super_admin', 'admin'],
        ],
    ],
];
