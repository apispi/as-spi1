<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GHSA-5vg9-5847-vvmq: Laravel's default `email` rule accepts a quoted local
 * part containing CRLF, e.g. "a\r\nb"@example.com. Every endpoint that stores
 * or mails an address therefore validates with `email:filter`, which rejects
 * it.
 *
 * The framework fix ships in 12.60; until that upgrade happens, these tests
 * are what keeps the endpoints from regressing to the permissive rule.
 */
class EmailValidationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public static function crlfAddresses(): array
    {
        return [
            // The dangerous form: the CRLF is *inside* a quoted local part,
            // so input trimming does not touch it and the default rule accepts
            // it.
            'quoted local part with CRLF' => ["\"a\r\nb\"@example.com"],
            'quoted local part with LF' => ["\"a\nb\"@example.com"],
        ];
    }

    /**
     * @dataProvider crlfAddresses
     */
    public function test_registration_rejects_crlf_bearing_addresses(string $address): void
    {
        $this->postJson('/api/register/start', ['email' => $address])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * @dataProvider crlfAddresses
     */
    public function test_login_rejects_crlf_bearing_addresses(string $address): void
    {
        $this->postJson('/api/login', ['email' => $address, 'password' => 'secret1234'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * A trailing CRLF never reaches validation: Laravel's TrimStrings
     * middleware strips it, so the stored and mailed address is clean. Pinned
     * because it explains why the case is absent from the provider above.
     */
    public function test_a_trailing_crlf_is_trimmed_rather_than_rejected(): void
    {
        $this->postJson('/api/register/start', ['email' => "ada@example.com\r\n"])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_ordinary_addresses_still_register(): void
    {
        $this->postJson('/api/register/start', ['email' => 'ada@example.com'])
            ->assertSuccessful();
    }
}
