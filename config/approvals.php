<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Approval workflows
|--------------------------------------------------------------------------
|
| Approval by risk, not just amount. Each workflow names the sensitive
| operation, who may authorise it (a permission; the requester's approving
| authority may always act too), and whether it is always required or only
| when a risk trigger fires. Whatever the trigger, the person who raised
| the request can never be the one who approves it.
|
*/

return [
    'workflows' => [
        'formula.activate' => [
            'label' => 'Formula activation',
            'description' => 'A new recipe version becomes the one production uses.',
            'permission' => 'formula.approve',
            'always' => true,
        ],
        'production.release' => [
            'label' => 'Manufacturing release',
            'description' => 'A batch is approved and its materials reserved.',
            'permission' => 'production.approve',
            // Only when a risk trigger fires: a non-standard formula, a
            // negative margin, a product below its yield target, abnormal
            // wastage on its recent batches, or a material whose price jumped.
            'always' => false,
        ],
        'qc.override' => [
            'label' => 'QC override',
            'description' => 'A lot QC rejected or held is released after all.',
            'permission' => 'qc.approve',
            'always' => true,
        ],
    ],

    // Maker-checker on QC: the person who booked a delivery in cannot be
    // the one who releases it.
    'qc_maker_checker' => (bool) env('ERP_QC_MAKER_CHECKER', true),

    // Salt for approval signatures. Set to a long random value in
    // production; changing it invalidates verification of past signatures.
    'signature_key' => env('ERP_SIGNATURE_KEY', env('APP_KEY')),
];
