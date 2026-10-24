<?php

use Illuminate\Support\Facades\Route;

/*
| CORS is closed everywhere: the integration API is server to server and the
| widget API is only called from the widget iframe on this same origin.
*/

test('no path answers a foreign origin with CORS headers', function (string $method, string $uri) {
    Route::middleware('api')->prefix('api/v1')->group(function (): void {
        Route::get('__cors-probe', fn () => response()->json(['ok' => true]));
    });

    $response = $this->call($method, $uri, server: [
        'HTTP_ORIGIN' => 'https://atacante.test',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
    ]);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
})->with([
    'api preflight' => ['OPTIONS', '/api/v1/__cors-probe'],
    'api request' => ['GET', '/api/v1/__cors-probe'],
    'agents broadcasting auth preflight' => ['OPTIONS', '/broadcasting/auth'],
    'agents broadcasting auth' => ['POST', '/broadcasting/auth'],
]);
