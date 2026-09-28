<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenBelongsToCurrentTeamMember
{
    private const MEMBER_DISALLOWED_ABILITIES = [
        'root',
        'write',
        'write:sensitive',
        'deploy',
        'read:sensitive',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $teamId = data_get($token, 'team_id');

        if (! $user || ! $token || is_null($teamId)) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $team = $user->teams()
            ->where('teams.id', $teamId)
            ->first();

        if (! $team) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $role = $team->pivot?->role;
        $disallowed = array_values(array_filter(
            self::MEMBER_DISALLOWED_ABILITIES,
            fn (string $ability): bool => $token->can($ability)
        ));

        if ($disallowed !== [] && ! in_array($role, ['admin', 'owner'], true)) {
            // MCP clients expect JSON-RPC envelopes (often only parsed on HTTP 200).
            if ($request->is('mcp') || $request->is('mcp/*')) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $request->input('id'),
                    'error' => [
                        'code' => -32003,
                        'message' => 'Missing required team role.',
                    ],
                ]);
            }

            return response()->json([
                'message' => 'This API token has permissions ('.implode(', ', $disallowed).') that exceed your current role as a team member. Members are restricted to read-only API access. Please revoke this token and create a new one with only read permissions.',
            ], 403);
        }

        return $next($request);
    }
}
