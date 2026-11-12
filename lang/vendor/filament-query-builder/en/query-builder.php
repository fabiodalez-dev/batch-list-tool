<?php

// Client 2026-10-30: the nested block reads as what it does. Only these keys
// differ from Filament's; the rest comes from the package.
return [
    'form' => [
        'or_groups' => [
            'label' => 'Alternatives',
            'group' => [
                'label' => 'Alternative',
            ],
            'block' => [
                'label' => 'OR group (any of)',
                'or' => 'OR',
            ],
        ],
    ],
];
