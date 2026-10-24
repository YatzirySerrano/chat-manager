<?php

use App\Models\Conversation;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| docs/openapi/v1.json documents exactly the /api/v1 routes the application
| registers: every route (method and path) is in the document and every
| documented operation exists, with the route name as operationId. A new,
| renamed or removed endpoint fails here until the document follows, and
| so does a field added to, renamed in or removed from an answer.
*/

/**
 * @return array<string, mixed>
 */
function openApiDocument(): array
{
    $json = file_get_contents(base_path('docs/openapi/v1.json'));

    expect($json)->toBeString();

    return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * "METHOD /path" => route name, for every registered api/v1 route.
 *
 * @return array<string, string|null>
 */
function registeredApiV1Operations(): array
{
    $operations = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $operations[strtoupper($method).' /'.$route->uri()] = $route->getName();
        }
    }

    ksort($operations);

    return $operations;
}

/**
 * "METHOD /path" => operationId, for every documented operation.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, string|null>
 */
function documentedOperations(array $document): array
{
    $operations = [];

    foreach ($document['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            $operations[strtoupper($method).' '.$path] = $operation['operationId'] ?? null;
        }
    }

    ksort($operations);

    return $operations;
}

test('the openapi document is valid json for openapi 3.1', function () {
    $document = openApiDocument();

    expect($document['openapi'])->toStartWith('3.1.')
        ->and($document['components']['securitySchemes'])->toHaveKeys(['IntegrationToken', 'VisitorSession']);
});

test('every api v1 route is documented and every documented operation exists', function () {
    $registered = registeredApiV1Operations();

    expect($registered)->not->toBeEmpty()
        ->and(documentedOperations(openApiDocument()))->toBe($registered);
});

test('every documented reference and security scheme resolves', function () {
    $document = openApiDocument();
    $schemes = array_keys($document['components']['securitySchemes']);

    preg_match_all('/"\$ref":\s*"#\/components\/(\w+)\/(\w+)"/', (string) file_get_contents(base_path('docs/openapi/v1.json')), $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    foreach ($matches as [, $kind, $name]) {
        expect($document['components'][$kind] ?? [])->toHaveKey($name);
    }

    foreach ($document['paths'] as $item) {
        foreach ($item as $operation) {
            expect($operation['responses'])->toHaveKey('500');

            foreach ($operation['security'] ?? [] as $requirement) {
                expect($schemes)->toContain(...array_keys($requirement));
            }
        }
    }
});

test('the documented schemas list exactly the fields the api answers', function () {
    $schemas = openApiDocument()['components']['schemas'];
    $fields = fn (string $schema): array => array_keys($schemas[$schema]['properties']);

    $connection = WebChatFixtures::connection();
    ['plain' => $plain] = WebChatFixtures::apiToken(['connection_ids' => [$connection->id]]);
    $visitor = WebChatVisitor::start($this, $connection)->assertCreated();
    WebChatVisitor::send($this, (string) $visitor->json('token'))->assertCreated();
    $conversation = Conversation::query()->sole();

    $health = $this->withToken($plain)->getJson(route('api.v1.health'))->assertOk();
    $list = $this->withToken($plain)->getJson(route('api.v1.conversations.index'))->assertOk();
    $messages = $this->withToken($plain)->getJson(route('api.v1.conversations.messages.index', ['conversation' => $conversation->id]))->assertOk();
    $history = WebChatVisitor::messages($this, (string) $visitor->json('token'))->assertOk();

    expect(array_keys($health->json()))->toBe($fields('Health'))
        ->and(array_keys($list->json('data.0')))->toBe($fields('Conversation'))
        ->and(array_keys($list->json('meta')))->toBe($fields('ListMeta'))
        ->and(array_keys($messages->json('data.0')))->toBe($fields('Message'))
        ->and(array_keys($visitor->json('data')))->toBe($fields('VisitorSession'))
        ->and(array_keys($visitor->json()))->toEqualCanonicalizing($fields('SessionWithToken'))
        ->and(array_keys($history->json('data.0')))->toBe($fields('VisitorMessage'));
});
