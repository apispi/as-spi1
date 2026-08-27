<?php

namespace Tests\Unit\Services;

use App\Services\Agent\DestructiveHeuristic;
use PHPUnit\Framework\TestCase;

class DestructiveHeuristicTest extends TestCase
{
    public function test_it_flags_side_effecting_names_in_several_casings(): void
    {
        foreach (['delete_user', 'user.delete', 'deleteUser', 'sendEmail', 'chargeCard', 'drop_table', 'deploy_service'] as $name) {
            $this->assertTrue(DestructiveHeuristic::isDestructive($name), "{$name} should be destructive");
        }
    }

    public function test_it_leaves_read_only_names_alone(): void
    {
        foreach (['search', 'get_user', 'list_orders', 'fetch_profile', 'read_file', 'lookup', 'query_db'] as $name) {
            $this->assertFalse(DestructiveHeuristic::isDestructive($name), "{$name} should be read-only");
        }
    }
}
