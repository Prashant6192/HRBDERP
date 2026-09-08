<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Company
    |--------------------------------------------------------------------------
    */

    'company' => [
        'name' => env('ERP_COMPANY_NAME', 'HRBD'),
        'currency' => env('ERP_CURRENCY', 'INR'),
        'currency_symbol' => env('ERP_CURRENCY_SYMBOL', '₹'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Numeric precision
    |--------------------------------------------------------------------------
    |
    | Every quantity, price and cost in this ERP is stored as a PostgreSQL
    | NUMERIC column and handled in PHP through brick/math. Floating point is
    | never used for a value that a person will reconcile against a physical
    | stock count or an invoice.
    |
    | 'quantity_scale' is deliberately generous: formulations are expressed in
    | percentages that, scaled to a batch, produce long decimals.
    |
    */

    'precision' => [
        'quantity_scale' => 6,
        'quantity_precision' => 20,
        'money_scale' => 4,
        'money_precision' => 20,
        'percentage_scale' => 6,
        'percentage_precision' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Formula security
    |--------------------------------------------------------------------------
    |
    | Product formulations are the company's principal trade secret. Holding
    | the 'formula.view' permission is necessary but not sufficient: a user
    | must additionally clear a second verification step, which grants a
    | short-lived unlock. See SECURITY_ARCHITECTURE.md.
    |
    */

    'formula_security' => [

        // Minutes an unlock remains valid before re-verification is required.
        // Administrators may override this at runtime; this is the fallback.
        'access_ttl_minutes' => (int) env('ERP_FORMULA_ACCESS_TTL_MINUTES', 20),

        // Bounds enforced on the administrator-configurable value, so the
        // setting cannot be widened into a permanent unlock.
        'min_ttl_minutes' => 5,
        'max_ttl_minutes' => 60,

        // When true a dedicated formula PIN is required. When false the user
        // re-enters their account password instead. Either way the secret is
        // hashed, never stored in a readable form.
        'require_pin' => (bool) env('ERP_FORMULA_REQUIRE_PIN', true),

        // Failed verification attempts allowed before the unlock endpoint is
        // locked out for the decay period.
        'max_attempts' => 5,
        'lockout_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    'auth' => [
        // Failed logins allowed per email+IP before throttling.
        'max_login_attempts' => 5,
        'login_decay_minutes' => 1,
    ],

];
