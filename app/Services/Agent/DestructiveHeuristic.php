<?php

namespace App\Services\Agent;

/**
 * Guesses whether an MCP tool is likely to have side effects, from its name.
 *
 * Deliberately conservative-to-broad: in safe exploration mode we would rather
 * block a harmless "update_cache" than let an autonomous model call
 * "delete_account". A blocked call is a finding, not a failure — it tells you
 * the agent reached for a destructive capability to meet the goal.
 */
class DestructiveHeuristic
{
    private const VERBS = [
        'delete', 'remove', 'destroy', 'drop', 'purge', 'erase', 'truncate',
        'update', 'modify', 'edit', 'patch', 'set', 'rename', 'move',
        'create', 'insert', 'add', 'write', 'upload', 'put', 'post',
        'send', 'email', 'notify', 'publish', 'post',
        'pay', 'charge', 'refund', 'transfer', 'purchase', 'order', 'checkout',
        'execute', 'exec', 'run', 'invoke', 'deploy', 'restart', 'reboot', 'kill',
        'grant', 'revoke', 'disable', 'enable', 'reset', 'rotate', 'approve', 'reject',
    ];

    public static function isDestructive(string $toolName): bool
    {
        // Split on non-letters so "delete_user", "user.delete", "deleteUser"
        // all surface their verb.
        $tokens = preg_split('/[^a-z]+/i', strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $toolName))) ?: [];

        foreach ($tokens as $token) {
            if ($token !== '' && in_array($token, self::VERBS, true)) {
                return true;
            }
        }

        return false;
    }
}
