<?php

use App\Actions\Conversations\ProcessInboundMessages;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\InboundMessageReceipt;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The role record created from one of the initial role definitions.
 */
function role(DefaultRole $role): Role
{
    return Role::findByName($role->value, 'web');
}

/**
 * A text message as WhatsApp Cloud API documents it in "messages" webhooks.
 *
 * @param  array<string, mixed>  $extra  Extra keys of the message (e.g. a referral).
 * @return array<string, mixed>
 */
function whatsappTextMessage(string $from, string $body = 'Hola', ?CarbonImmutable $at = null, ?string $id = null, array $extra = []): array
{
    return [
        'from' => $from,
        'id' => $id ?? 'wamid.'.fake()->unique()->bothify('??##########'),
        'timestamp' => (string) ($at ?? CarbonImmutable::now())->getTimestamp(),
        'type' => 'text',
        'text' => ['body' => $body],
        ...$extra,
    ];
}

/**
 * A simulated "messages" webhook body of the WhatsApp Cloud API.
 *
 * @param  list<array<string, mixed>>  $messages
 * @param  array<string, string>  $profileNames  wa_id => profile name
 * @return array<string, mixed>
 */
function whatsappWebhook(Connection $connection, array $messages, array $profileNames = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '102290129340398',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $connection->phone_number_id],
                    'contacts' => array_map(fn (string $waId, string $name): array => ['profile' => ['name' => $name], 'wa_id' => $waId], array_keys($profileNames), $profileNames),
                    'messages' => $messages,
                ],
            ]],
        ]],
    ];
}

/**
 * Receive one simulated text message through the real inbound pipeline.
 *
 * @param  array<string, mixed>  $extra
 */
function receiveWhatsapp(Connection $connection, string $from = '5215512345678', string $body = 'Hola', ?CarbonImmutable $at = null, ?string $id = null, array $extra = [], ?string $profileName = 'Laura Pérez'): InboundMessageReceipt
{
    $payload = whatsappWebhook($connection, [whatsappTextMessage($from, $body, $at, $id, $extra)], $profileName === null ? [] : [$from => $profileName]);

    return app(ProcessInboundMessages::class)->handle($payload)[0];
}

/**
 * A user whose (custom) role grants exactly the given permissions and scope.
 *
 * @param  list<Permission>  $permissions
 * @param  list<Department>  $departments
 */
function conversationUser(array $permissions, DataScope $scope = DataScope::Assigned, array $departments = []): User
{
    $role = Role::factory()->scope($scope)->withPermissions($permissions)->create();

    return User::factory()->withRole($role)->inDepartments($departments)->create();
}
