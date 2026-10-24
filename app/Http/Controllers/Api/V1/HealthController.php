<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\AuthenticateApiToken;
use App\Models\ApiToken;
use App\Models\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/health: lets an integration check its token and whether the
 * connections it may use are operating. Only the token's own name,
 * abilities and expiry and each connection's name, channel and an
 * operational flag: never provider identifiers, credentials, phones or
 * emails.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        $connections = Connection::query()
            ->whereIn('id', array_map('intval', $token->connection_ids))
            ->notDeleted()
            ->with('webChatWidget')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 'ok',
            'api_version' => 'v1',
            'server_time' => now()->toIso8601String(),
            'token' => [
                'id' => $token->public_id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at->toIso8601String(),
            ],
            'connections' => $connections->map(fn (Connection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'channel' => $connection->channel->value,
                'operational' => $connection->isOperational() && $connection->isConfigured(),
            ])->values()->all(),
        ]);
    }
}
