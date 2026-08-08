<?php

namespace Tests\Feature;

use Tests\TestCase;

class GoogleAnalyticsTest extends TestCase
{
    public function test_gtag_snippet_is_rendered_when_a_measurement_id_is_configured(): void
    {
        config(['services.google_analytics.id' => 'G-TEST123']);

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('https://www.googletagmanager.com/gtag/js?id=G-TEST123', false)
            ->assertSee("gtag('config', \"G-TEST123\")", false);
    }

    public function test_gtag_snippet_is_absent_when_no_measurement_id_is_configured(): void
    {
        config(['services.google_analytics.id' => null]);

        $response = $this->get('/');

        $response->assertOk()->assertDontSee('googletagmanager.com/gtag/js', false);
    }
}
