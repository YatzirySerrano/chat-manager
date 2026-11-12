<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Phase two
    |--------------------------------------------------------------------------
    |
    | Service rules beyond the phase-one operation: readiness confirmation
    | («Lista para atención»), minimum wait / chronological order and their
    | overrides, department and category service rules, SLA targets, the SLA
    | evaluator and its automatic alerts, the SLA monitor, SLA compliance
    | from response cycles and per-agent productivity. Off by default: the
    | application then behaves as phase one, with the code, data, migrations
    | and stored settings preserved.
    |
    | Read only through App\Support\Features::phaseTwo(). After changing the
    | variable run `php artisan config:cache`, `php artisan queue:restart`
    | and `php artisan schedule:clear-cache` (docs/reglas-de-servicio.md).
    |
    */

    'phase_two' => (bool) env('FEATURE_PHASE_TWO', false),

];
