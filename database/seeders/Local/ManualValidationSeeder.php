<?php

namespace Database\Seeders\Local;

use App\Actions\Connections\CreateConnection;
use App\Actions\Contacts\AddContactNote;
use App\Actions\Contacts\CreateContact;
use App\Actions\Conversations\AcceptConversation;
use App\Actions\Conversations\AddConversationNote;
use App\Actions\Conversations\CloseConversation;
use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Conversations\PutConversationOnHold;
use App\Actions\Conversations\TransferConversation;
use App\Actions\Departments\CreateDepartment;
use App\Actions\QuickReplies\CreateQuickReply;
use App\Actions\Roles\SaveRole;
use App\Actions\Tags\CreateTag;
use App\Actions\Tags\SyncContactTags;
use App\Actions\Users\CreateUser;
use App\Enums\ColorSwatch;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\QuickReply;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use App\Support\ManualValidation;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Small fictitious scenario for validating increments 01, A and B by hand.
 * LOCAL DEVELOPMENT ONLY: it is not called by DatabaseSeeder, installation,
 * migrations or deployments, and refuses to run outside APP_ENV=local on the
 * "chatmanager" database.
 *
 *   php artisan db:seed --class="Database\Seeders\Local\ManualValidationSeeder"
 *
 * Everything is created through the application's own actions, acting as the
 * existing administrator, and is idempotent: records are found by their
 * markers (App\Support\ManualValidation) and operations are only applied to
 * conversations still in their initial state, so re-running it never
 * duplicates data nor undoes what was tested by hand. Existing data is never
 * modified. Remove it with `php artisan chat-manager:demo-cleanup`.
 */
class ManualValidationSeeder extends Seeder
{
    public function run(): void
    {
        ManualValidation::ensureAllowed();

        $summary = $this->build();

        $this->command->info('Escenario Demo B listo.');
        $this->command->line('Credenciales de las cuentas demo (solo locales): '.$summary['credentials']);
    }

    /**
     * @param  string|null  $credentialsPath  Where to write the demo passwords (default: outside the repository, in the user's home).
     * @return array{credentials: string, conversations: array<string, int>}
     */
    public function build(?string $credentialsPath = null): array
    {
        $admin = $this->administrator();

        $role = $this->role($admin);
        $sales = $this->department('sales', 'Ventas', 'Atención de prospectos y ventas (datos de la validación Demo B).', ColorSwatch::Emerald, $admin);
        $support = $this->department('support', 'Soporte', 'Soporte técnico y facturación (datos de la validación Demo B).', ColorSwatch::Blue, $admin);

        $accounts = [
            'valeria' => ['name' => ManualValidation::PREFIX.'Valeria Ríos (Ventas)', 'email' => 'valeria.ventas@'.ManualValidation::EMAIL_DOMAIN, 'department' => $sales],
            'oscar' => ['name' => ManualValidation::PREFIX.'Óscar Lima (Soporte)', 'email' => 'oscar.soporte@'.ManualValidation::EMAIL_DOMAIN, 'department' => $support],
        ];
        [$agents, $credentials] = $this->agents($accounts, $role, $admin, $credentialsPath ?? ManualValidation::credentialsPath());

        $connection = $this->connection([$sales, $support], $sales, $admin);
        $tags = $this->tags($admin);
        $this->quickReplies($sales, $support, $admin);

        // Mario was registered by hand before writing on WhatsApp; his first
        // message links the WhatsApp identity to that same contact.
        $mario = $this->manualContact(ManualValidation::CUSTOMERS['mario'], $admin);
        $this->tagOnce($mario, $tags['interested'], $admin);

        $laura = $this->inbound('laura', 'Hola, vi su anuncio y quiero información de precios.', minutesAgo: 20, referral: ManualValidation::demoAdReferral('laura'));
        $marioConversation = $this->inbound('mario', 'Buenas tardes, ya hice el pedido, ¿cuándo llega?', minutesAgo: 90);
        $sofia = $this->inbound('sofia', 'Necesito la factura de mi compra de la semana pasada.', minutesAgo: 26 * 60);
        $this->tagOnce($sofia->contact()->firstOrFail(), $tags['invoice'], $admin);

        $this->prepareOnHold($marioConversation, $agents['valeria']);
        $this->prepareClosed($sofia, $agents['valeria'], $agents['oscar'], $support);

        return [
            'credentials' => $credentials,
            'conversations' => [
                'pending' => $laura->id,
                'on_hold' => $marioConversation->id,
                'closed' => $sofia->id,
            ],
        ];
    }

    /**
     * The existing root administrator acts on behalf of the scenario; it is never modified.
     */
    private function administrator(): User
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('is_root', true))
            ->orderBy('id')
            ->first()
            ?? throw new RuntimeException('Create the administrator first (php artisan app:create-administrator).');
    }

    private function role(User $admin): Role
    {
        return Role::query()->where('label', ManualValidation::ROLE_LABEL)->first()
            ?? app(SaveRole::class)->handle(null, [
                'label' => ManualValidation::ROLE_LABEL,
                'description' => 'Rol de prueba de la validación manual: permisos actuales del agente. Puedes quitarle permisos para comprobar qué acciones desaparecen.',
                'scope' => DataScope::Assigned,
                'permissions' => array_map(fn (Permission $permission): string => $permission->value, DefaultRole::Agent->permissions()),
            ], $admin);
    }

    private function department(string $key, string $name, string $description, ColorSwatch $color, User $admin): Department
    {
        return Department::query()->where('slug', ManualValidation::DEPARTMENTS[$key])->first()
            ?? app(CreateDepartment::class)->handle([
                'name' => ManualValidation::PREFIX.$name,
                'description' => $description,
                'color' => $color,
            ], $admin);
    }

    /**
     * @param  array<string, array{name: string, email: string, department: Department}>  $accounts
     * @return array{array<string, User>, string}
     */
    private function agents(array $accounts, Role $role, User $admin, string $path): array
    {
        $writeCredentials = ! is_file($path);
        $agents = [];
        $lines = [];

        foreach ($accounts as $key => $account) {
            $user = User::query()->where('email', $account['email'])->first();
            $password = Str::password(20, symbols: false);

            if ($user === null) {
                $user = app(CreateUser::class)->handle([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => $password,
                    'role' => $role,
                    'department_ids' => [$account['department']->id],
                ], $admin);
                $writeCredentials = true;
            } elseif ($writeCredentials) {
                // The credentials file was lost: demo accounts get a new local password.
                $user->forceFill(['password' => $password])->save();
            }

            $agents[$key] = $user;
            $lines[] = "{$account['name']}\n  correo: {$account['email']}\n  contraseña: {$password}\n";
        }

        if ($writeCredentials) {
            $directory = dirname($path);

            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }

            file_put_contents($path, "Chat Manager · cuentas de la validación Demo B (solo locales, no compartir)\nhttp://localhost:8000/login\n\n".implode("\n", $lines));
            chmod($path, 0600);
        }

        return [$agents, $path];
    }

    /**
     * @param  list<Department>  $departments
     */
    private function connection(array $departments, Department $default, User $admin): Connection
    {
        return Connection::query()->where('phone_number_id', ManualValidation::PHONE_NUMBER_ID)->first()
            ?? app(CreateConnection::class)->handle([
                'attributes' => [
                    'name' => ManualValidation::PREFIX.'WhatsApp simulado',
                    'display_phone_number' => ManualValidation::DISPLAY_PHONE_NUMBER,
                    'phone_number_id' => ManualValidation::PHONE_NUMBER_ID,
                    'default_department_id' => $default->id,
                    'reopen_window_minutes' => 120,
                ],
                'secrets' => [],
                'department_ids' => array_map(fn (Department $department): int => $department->id, $departments),
            ], $admin);
    }

    /**
     * @return array<string, Tag>
     */
    private function tags(User $admin): array
    {
        $definitions = [
            'interested' => ['Interesado', 'Prospecto con interés de compra (dato de prueba).', ColorSwatch::Emerald],
            'invoice' => ['Requiere factura', 'Cliente que pidió factura (dato de prueba).', ColorSwatch::Amber],
        ];

        $tags = [];

        foreach ($definitions as $key => [$name, $description, $color]) {
            $tags[$key] = Tag::query()->where('slug', ManualValidation::TAGS[$key])->first()
                ?? app(CreateTag::class)->handle(['name' => ManualValidation::PREFIX.$name, 'description' => $description, 'color' => $color], $admin);
        }

        return $tags;
    }

    private function quickReplies(Department $sales, Department $support, User $admin): void
    {
        $definitions = [
            'sales' => ['Saludo de ventas', '¡Hola! Gracias por escribirnos. ¿Qué producto te interesa? (respuesta de prueba)', $sales],
            'support' => ['Seguimiento de soporte', 'Hola, damos seguimiento a tu solicitud. ¿Puedes compartir tu número de pedido? (respuesta de prueba)', $support],
        ];

        foreach ($definitions as $key => [$title, $body, $department]) {
            $exists = QuickReply::query()->where('shortcut', ManualValidation::QUICK_REPLIES[$key])->whereHas('departments', fn ($query) => $query->whereKey($department->id))->exists();

            if (! $exists) {
                app(CreateQuickReply::class)->handle([
                    'title' => ManualValidation::PREFIX.$title,
                    'shortcut' => ManualValidation::QUICK_REPLIES[$key],
                    'body' => $body,
                    'is_global' => false,
                    'department_ids' => [$department->id],
                ], $admin);
            }
        }
    }

    /**
     * @param  array{wa_id: string, name: string}  $customer
     */
    private function manualContact(array $customer, User $admin): Contact
    {
        $phone = PhoneNumber::normalize('+'.$customer['wa_id']);
        $contact = Contact::query()->where('phone_e164', $phone)->first();

        if ($contact !== null) {
            return $contact;
        }

        $contact = app(CreateContact::class)->handle([
            'name' => $customer['name'],
            'phone_e164' => $phone,
            'email' => 'mario.ruiz@example.com',
            'custom_fields' => null,
        ], $admin);

        app(AddContactNote::class)->handle($contact, 'Registrado a mano en mostrador antes de escribir por WhatsApp (dato de prueba).', $admin);

        return $contact;
    }

    private function tagOnce(Contact $contact, Tag $tag, User $admin): void
    {
        if (! $contact->tags()->whereKey($tag->id)->exists()) {
            app(SyncContactTags::class)->handle($contact, array_values([...array_map('intval', $contact->tags()->pluck('tags.id')->all()), $tag->id]), $admin);
        }
    }

    /**
     * The customer's first message, through the real inbound pipeline (a
     * fixed message id makes a second run a harmless duplicate).
     *
     * @param  array<string, string>|null  $referral
     */
    private function inbound(string $customer, string $text, int $minutesAgo, ?array $referral = null): Conversation
    {
        ['wa_id' => $waId, 'name' => $name] = ManualValidation::CUSTOMERS[$customer];

        $receipts = app(ProcessInboundMessages::class)->handle(ManualValidation::inboundPayload(
            $waId,
            $name,
            "wamid.DEMO-B.seed.{$customer}.1",
            $text,
            now()->subMinutes($minutesAgo)->getTimestamp(),
            $referral,
        ));

        return $receipts[0]->conversation ?? throw new RuntimeException("The simulated message of {$customer} was not received.");
    }

    /**
     * Mario: accepted by the sales agent, with an internal note, waiting for a payment receipt.
     */
    private function prepareOnHold(Conversation $conversation, User $agent): void
    {
        if ($conversation->status !== ConversationStatus::Pending || $conversation->events()->count() > 1) {
            return;
        }

        $conversation = app(AcceptConversation::class)->handle($conversation, $agent);
        app(AddConversationNote::class)->handle($conversation, $agent, 'El cliente dice que pagó por transferencia; falta el comprobante (nota interna de prueba).');
        app(PutConversationOnHold::class)->handle($conversation->refresh(), $agent, 'Esperando comprobante de pago');
    }

    /**
     * Sofía: written 26 hours ago (customer service window already expired),
     * transferred from sales to support, solved there and closed.
     */
    private function prepareClosed(Conversation $conversation, User $salesAgent, User $supportAgent, Department $support): void
    {
        if ($conversation->status !== ConversationStatus::Pending || $conversation->events()->count() > 1) {
            return;
        }

        $conversation = app(AcceptConversation::class)->handle($conversation, $salesAgent);
        $conversation = app(TransferConversation::class)->handle($conversation->refresh(), $support, null, $salesAgent, 'Solicitud de factura: la atiende Soporte');
        $conversation = app(AcceptConversation::class)->handle($conversation->refresh(), $supportAgent);
        app(AddConversationNote::class)->handle($conversation, $supportAgent, 'Factura enviada por correo electrónico (nota interna de prueba).');
        app(CloseConversation::class)->handle($conversation->refresh(), $supportAgent, 'Factura entregada; caso resuelto');
    }
}
