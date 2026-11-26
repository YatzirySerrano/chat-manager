<?php

/*
| Who is working right now (App\Services\Conversations\AgentPresence).
|
| - online_minutes (AGENT_ONLINE_MINUTES): an agent is "en sesión" while one
|   of their sessions made a request in the last N minutes (the open inbox
|   keeps it alive); logging out ends it at once.
| - auto_assign_only_online (AUTO_ASSIGN_ONLY_ONLINE): the automatic
|   distribution only hands requests to agents in session.
*/

return [
    'online_minutes' => (int) env('AGENT_ONLINE_MINUTES', 15),
    'auto_assign_only_online' => (bool) env('AUTO_ASSIGN_ONLY_ONLINE', true),
];
