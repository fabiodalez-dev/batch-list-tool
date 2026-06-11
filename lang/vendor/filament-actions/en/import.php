<?php

/*
 * Feedback1 gaps (misc) — partial override of the `filament-actions`
 * translation namespace (vendor/filament/actions/resources/lang/en/import.php).
 *
 * Laravel merges namespace overrides in lang/vendor/<namespace>/<locale>/
 * recursively over the package defaults, so ONLY the keys listed here are
 * replaced — everything else keeps the vendor wording.
 *
 * Client feedback: the import modal said "Upload a CSV file" although the
 * importers accept Excel files too.
 */
return [

    'modal' => [

        'form' => [

            'file' => [

                'placeholder' => 'Upload an Excel or CSV file',

            ],

        ],

    ],

];
