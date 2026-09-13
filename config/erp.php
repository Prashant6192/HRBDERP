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
        // Our own GSTIN and PAN. A supplier's bill prints ours as the
        // buyer's; the bill reader is told never to mistake them for the
        // vendor's.
        'gstin' => env('ERP_COMPANY_GSTIN'),
        'pan' => env('ERP_COMPANY_PAN'),
        // The clock on the wall of the factory. Timestamps are stored in UTC
        // and shown in this zone, so a shift's records read the same from
        // any browser.
        'timezone' => env('ERP_TIMEZONE', 'Asia/Kolkata'),
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
    | AI assistance
    |--------------------------------------------------------------------------
    |
    | Reading supplier bills into goods receipts. Needs an Anthropic API key;
    | without one the receipt screens fall back to manual entry. The model is
    | swappable; claude-opus-5 reads Indian GST invoices reliably, and
    | claude-fable-5-1 is the most capable option at a higher price.
    |
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ERP_AI_MODEL', 'claude-opus-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality control
    |--------------------------------------------------------------------------
    |
    | A QC decision releases stock to the floor or condemns it, so the person
    | signing it re-enters their personal PIN — the same PIN that unlocks
    | formulations, set under Settings → Security.
    |
    */

    'qc' => [
        'require_pin' => (bool) env('ERP_QC_REQUIRE_PIN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    */

    'labels' => [
        // The small batch sticker the store puts on a drum or a carton, in mm.
        'batch_sticker' => ['width' => 80, 'height' => 50],
        // The QC slip printed at the checkpoint, in mm.
        'qc_slip' => ['width' => 80, 'height' => 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stock alerts
    |--------------------------------------------------------------------------
    |
    | Each item carries its own minimum_stock (critical) and reorder_level
    | (low). The "moderate" band sits above the reorder level; its width is
    | reorder_level x moderate_multiplier. See StockAlertService.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Factory intelligence
    |--------------------------------------------------------------------------
    |
    | The figures behind the predictions: how far back consumption is
    | averaged, how far ahead demand is looked at, and what counts as slow
    | or at risk. All in days unless stated.
    |
    */

    'intelligence' => [
        // Consumption is averaged over this many days of ledger history.
        'consumption_window_days' => (int) env('ERP_CONSUMPTION_WINDOW_DAYS', 90),
        // An item with less history than this is averaged over at least this many days.
        'consumption_floor_days' => 14,
        // Demand is looked at this far ahead when recommending an order.
        'planning_horizon_days' => (int) env('ERP_PLANNING_HORIZON_DAYS', 30),
        // When neither the item nor a supplier says how long delivery takes.
        'default_lead_time_days' => (int) env('ERP_DEFAULT_LEAD_TIME_DAYS', 7),
        // Order "soon" when the order date is within this many days.
        'order_soon_days' => 7,
        // Slow-moving buckets, oldest first.
        'slow_moving_buckets' => [180, 90, 60, 30],
        // Lots expiring within this many days are assessed for expiry risk.
        'expiry_risk_days' => (int) env('ERP_EXPIRY_RISK_DAYS', 180),
        // Supplier prices are compared over this many days of receipts.
        'price_history_days' => 365,
    ],

    'stock_alerts' => [
        'moderate_multiplier' => env('ERP_STOCK_MODERATE_MULTIPLIER', '2'),

        // Lots expiring within this many days are flagged on the dashboard.
        'expiry_warning_days' => (int) env('ERP_EXPIRY_WARNING_DAYS', 90),
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
