<?php

use App\Exceptions\ConversationConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/*
| Every /api/v1 error has the same JSON shape, {message, code} (plus
| "errors" on 422), with Cache-Control: no-store and the request id, whatever
| raised it. Probe routes under api/v1 raise each failure.
*/

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/__errors')->group(function (): void {
        Route::get('ok', fn () => response()->json(['data' => 'ok']));
        Route::get('bad-request', fn () => throw new BadRequestHttpException('detalle interno'));
        Route::get('unauthenticated', fn () => throw new AuthenticationException('token vencido'));
        Route::get('forbidden', fn () => throw new AuthorizationException('regla interna'));
        Route::get('missing-model', fn () => throw (new ModelNotFoundException)->setModel('App\\Models\\Conversation', [42]));
        Route::get('conflict-code', fn () => throw new ConversationConflict('idempotency_key_reused'));
        Route::get('conflict-text', fn () => throw ConversationConflict::alreadyTaken());
        Route::get('validation', fn () => Validator::make([], ['text' => ['required']])->validate());
        Route::get('crash', fn () => throw new RuntimeException('SQLSTATE secreto en /var/www/app.php'));
        Route::get('deadlock', fn () => throw new QueryException('mysql', 'insert ignore into `cache` …', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')));
        Route::get('throttled', fn () => response()->json(['data' => 'ok']))->middleware('throttle:api-v1-read');
    });
});

test('api v1 errors share one JSON shape and never cache', function (string $path, int $status, string $code) {
    $response = $this->get('/api/v1/__errors/'.$path);

    $response->assertStatus($status)
        ->assertJsonPath('code', $code)
        ->assertHeader('X-Request-Id');

    expect(array_keys($response->json()))->toBe(['message', 'code'])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Content-Type'))->toContain('application/json');
})->with([
    'bad request' => ['bad-request', 400, 'bad_request'],
    'unauthenticated' => ['unauthenticated', 401, 'unauthenticated'],
    'forbidden' => ['forbidden', 403, 'forbidden'],
    'unknown route' => ['does-not-exist', 404, 'not_found'],
    'record not found' => ['missing-model', 404, 'not_found'],
    'conflict with code' => ['conflict-code', 409, 'idempotency_key_reused'],
    'conflict with explanation' => ['conflict-text', 409, 'conflict'],
    'server error' => ['crash', 500, 'server_error'],
    'deadlock' => ['deadlock', 503, 'temporarily_unavailable'],
]);

test('a deadlock is a retryable answer, never a permanent conflict nor its details', function () {
    $response = $this->get('/api/v1/__errors/deadlock');

    $response->assertStatus(503)
        ->assertJsonPath('code', 'temporarily_unavailable')
        ->assertHeader('Retry-After', '1');
    expect($response->getContent())->not->toContain('cache')
        ->and($response->getContent())->not->toContain('SQLSTATE');
});

test('a missing record and an unknown route answer exactly the same', function () {
    $missing = $this->get('/api/v1/__errors/missing-model');
    $unknown = $this->get('/api/v1/__errors/does-not-exist');

    expect($missing->json())->toBe($unknown->json())
        ->and($missing->getContent())->not->toContain('Conversation')
        ->and($missing->getContent())->not->toContain('42');
});

test('an unauthenticated answer asks for a bearer token without saying why', function () {
    $response = $this->get('/api/v1/__errors/unauthenticated');

    $response->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
    expect($response->getContent())->not->toContain('vencido');
});

test('validation errors list the fields', function () {
    $this->get('/api/v1/__errors/validation')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['message', 'code', 'errors' => ['text']]);
});

test('a server error never leaks its details, even with debug on', function () {
    config(['app.debug' => true]);

    $response = $this->get('/api/v1/__errors/crash');

    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('SQLSTATE')
        ->and($response->getContent())->not->toContain('/var/www')
        ->and($response->json())->not->toHaveKey('trace');
});

test('rate limited requests answer 429 with Retry-After', function () {
    config(['integrations.limits.reads_per_minute' => 1]);

    $this->get('/api/v1/__errors/throttled')->assertOk();
    $response = $this->get('/api/v1/__errors/throttled');

    $response->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
});

test('successful api v1 answers are not cached either', function () {
    $response = $this->get('/api/v1/__errors/ok');

    $response->assertOk()->assertExactJson(['data' => 'ok']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('web pages keep their usual error answers', function () {
    $this->getJson('/no-existe-esta-pagina')->assertNotFound()->assertExactJson(['message' => 'Not Found']);
});
