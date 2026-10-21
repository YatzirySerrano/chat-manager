<?php

namespace App\Services\Conversations;

use App\Models\Connection;
use App\Models\Department;
use App\Services\PlatformSettings;

/**
 * Chooses the queue of a new conversation from explicit configuration only:
 * the connection's default department, otherwise the platform's default
 * department when it may receive that connection's conversations. Without
 * such configuration the conversation stays without department, in the
 * queue only users with organization-wide scope see. No department is ever
 * guessed from the content of the messages.
 */
class ConversationRouter
{
    public function __construct(private PlatformSettings $settings) {}

    /**
     * @return array{department: Department|null, routed_by: 'connection_default'|'platform_default'|'unrouted'}
     */
    public function route(Connection $connection): array
    {
        $authorized = array_map('intval', $connection->departments()->pluck('departments.id')->all());

        $connectionDefault = $connection->default_department_id === null
            ? null
            : Department::query()->active()->find($connection->default_department_id);

        if ($connectionDefault !== null && ($authorized === [] || in_array($connectionDefault->id, $authorized, true))) {
            return ['department' => $connectionDefault, 'routed_by' => 'connection_default'];
        }

        $platformDefaultId = $this->settings->get('operations.default_department_id');
        $platformDefault = is_int($platformDefaultId) ? Department::query()->active()->find($platformDefaultId) : null;

        if ($platformDefault !== null && ($authorized === [] || in_array($platformDefault->id, $authorized, true))) {
            return ['department' => $platformDefault, 'routed_by' => 'platform_default'];
        }

        return ['department' => null, 'routed_by' => 'unrouted'];
    }
}
