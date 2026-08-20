<?php

namespace Tests\Feature;

use App\Services\Assertions\Assertion;
use Tests\TestCase;

class AssertionVocabularyTest extends TestCase
{
    /**
     * The assertions panel hard-codes the operator list so it can render a
     * dropdown without a round-trip. If the two drift apart, the UI offers
     * operators the API rejects (or hides ones it accepts), so pin them here.
     */
    public function test_the_frontend_operator_list_matches_the_backend(): void
    {
        $vue = file_get_contents(resource_path('js/components/AssertionsPanel.vue'));

        $this->assertMatchesRegularExpression('/const OPERATORS = \[(.*?)\];/s', $vue);
        preg_match('/const OPERATORS = \[(.*?)\];/s', $vue, $m);

        preg_match_all("/'([a-z_]+)'/", $m[1], $found);

        $this->assertSame(
            Assertion::operators(),
            $found[1],
            'AssertionsPanel.vue OPERATORS is out of sync with Assertion::OPERATORS.'
        );
    }

    public function test_operators_needing_no_expected_value_match_the_frontend(): void
    {
        $vue = file_get_contents(resource_path('js/components/AssertionsPanel.vue'));

        preg_match('/const NO_EXPECTED = \[(.*?)\];/s', $vue, $m);
        preg_match_all("/'([a-z_]+)'/", $m[1], $found);

        $backend = array_keys(array_filter(
            Assertion::OPERATORS,
            fn ($needsExpected) => ! $needsExpected
        ));

        $this->assertSame($backend, $found[1]);
    }
}
