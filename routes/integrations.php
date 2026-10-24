<?php

use App\Http\Controllers\Admin\ApiTokenController;
use Illuminate\Support\Facades\Route;

/*
| Administration of the integration API tokens (admin/integrations/tokens,
| names integrations.tokens.*), behind auth and verified. Authorization is
| enforced by ApiTokenPolicy (integrations.view / integrations.manage).
| Owner: workstream api-integracion.
*/

Route::middleware(['auth', 'verified'])->prefix('admin/integrations/tokens')->name('integrations.tokens.')->group(function (): void {
    Route::get('/', [ApiTokenController::class, 'index'])->name('index');
    Route::post('/', [ApiTokenController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::delete('{apiToken}', [ApiTokenController::class, 'destroy'])->name('destroy');
});
