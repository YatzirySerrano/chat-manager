<?php

use Illuminate\Support\Facades\Route;

/*
| /api/v1 (stateless "api" group: no session, cookies, CSRF or CORS). Two
| surfaces with their own principals: the integration API (Bearer cm_live_…,
| routes/api/integration.php) and the web chat visitor API (Bearer wvs_…,
| called only from the widget iframe, routes/api/widget.php).
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    require __DIR__.'/api/integration.php';
    require __DIR__.'/api/widget.php';
});
