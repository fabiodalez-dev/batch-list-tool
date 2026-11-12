<?php

/*
 * Only the payload limits differ from Livewire's defaults (the other keys
 * come from the package config). Client 2026-10-30: an "OR group" inside the
 * rules (R16 OR R12, AND Bastardello) needs a deeper property path than 10,
 * e.g. tableFilters.queryBuilder.rules.<id>.data.groups.<id>.rules.<id>.data.settings.values.
 */
return [
    'payload' => [
        'max_size' => 1024 * 1024,
        'max_nesting_depth' => 20,
        'max_calls' => 50,
        'max_components' => 200,
    ],
];
