<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnvironmentImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function env(User $user, array $vars, string $name = 'Staging'): Environment
    {
        return $user->environments()->create(['name' => $name, 'variables' => $vars, 'is_default' => false]);
    }

    public function test_export_strips_secret_values_but_keeps_the_rest(): void
    {
        $user = User::factory()->create();
        $env = $this->env($user, [
            ['key' => 'base_url', 'value' => 'https://api.example.com', 'secret' => false],
            ['key' => 'token', 'value' => 'super-secret', 'secret' => true],
        ]);

        $res = $this->actingAs($user)->get("/api/environments/{$env->id}/export")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="staging.spi-env.json"');

        $doc = $res->json();
        $this->assertSame('1.0', $doc['spi_environment']);
        $this->assertSame('Staging', $doc['name']);
        // Non-secret value present; secret value blanked but flagged.
        $this->assertSame('https://api.example.com', $doc['variables'][0]['value']);
        $this->assertSame('token', $doc['variables'][1]['key']);
        $this->assertSame('', $doc['variables'][1]['value']);
        $this->assertTrue($doc['variables'][1]['secret']);
        // The secret must never appear anywhere in the export.
        $this->assertStringNotContainsString('super-secret', json_encode($doc));
    }

    public function test_import_creates_an_environment(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/environments/import', [
            'name' => 'Prod',
            'variables' => [
                ['key' => 'base_url', 'value' => 'https://prod.example.com', 'secret' => false],
                ['key' => 'api_key', 'value' => '', 'secret' => true],
            ],
        ])->assertStatus(201)->assertJsonPath('name', 'Prod');

        $this->assertDatabaseHas('environments', ['user_id' => $user->id, 'name' => 'Prod']);
    }

    public function test_import_suffixes_a_clashing_name(): void
    {
        $user = User::factory()->create();
        $this->env($user, [], 'Prod');

        $this->actingAs($user)->postJson('/api/environments/import', ['name' => 'Prod', 'variables' => []])
            ->assertStatus(201)->assertJsonPath('name', 'Prod (2)');
    }

    public function test_import_rejects_an_invalid_variable_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/environments/import', [
            'name' => 'Bad',
            'variables' => [['key' => 'has space', 'value' => 'x']],
        ])->assertStatus(422)->assertJsonValidationErrors(['variables.0.key']);
    }

    public function test_export_is_workspace_scoped(): void
    {
        $owner = User::factory()->create();
        $env = $this->env($owner, []);

        $this->actingAs(User::factory()->create())
            ->get("/api/environments/{$env->id}/export")->assertStatus(404);
    }

    public function test_a_round_trip_preserves_non_secret_variables(): void
    {
        $user = User::factory()->create();
        $env = $this->env($user, [
            ['key' => 'base_url', 'value' => 'https://api.example.com', 'secret' => false],
            ['key' => 'token', 'value' => 'secret', 'secret' => true],
        ]);

        $doc = $this->actingAs($user)->get("/api/environments/{$env->id}/export")->json();
        $this->actingAs($user)->postJson('/api/environments/import', $doc)->assertStatus(201);

        $imported = Environment::where('name', 'Staging (2)')->firstOrFail();
        $map = collect($imported->variables)->keyBy('key');
        $this->assertSame('https://api.example.com', $map['base_url']['value']);
        $this->assertTrue($map['token']['secret']);
        $this->assertSame('', $map['token']['value']); // secret re-entry required
    }
}
