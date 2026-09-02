<?php

namespace Tests\Unit\Services;

use App\Services\Fuzz\FuzzGenerator;
use PHPUnit\Framework\TestCase;

class FuzzGeneratorTest extends TestCase
{
    private FuzzGenerator $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gen = new FuzzGenerator;
    }

    public function test_the_first_variant_is_the_unchanged_baseline(): void
    {
        $variants = $this->gen->generate(['name' => 'Ada']);

        $this->assertSame('baseline (unchanged)', $variants[0]['label']);
        $this->assertFalse($variants[0]['expects_reject']);
        $this->assertSame(['name' => 'Ada'], $variants[0]['body']);
    }

    public function test_it_targets_string_fields_with_type_and_injection_mutations(): void
    {
        $labels = array_column($this->gen->generate(['q' => 'hello']), 'label');

        $this->assertContains('omit "q"', $labels);
        $this->assertContains('"q" = 12345 (wrong type)', $labels);
        $this->assertContains('"q" = null', $labels);
        $this->assertContains('"q" = 10k chars (oversized)', $labels);
        $this->assertTrue((bool) array_filter($labels, fn ($l) => str_contains($l, 'injection')));
    }

    public function test_it_generates_boundary_and_type_violations_for_numbers(): void
    {
        $variants = $this->gen->generate(['age' => 30]);
        $badType = collect($variants)->firstWhere('label', '"age" = not-a-number (wrong type)');

        $this->assertNotNull($badType);
        $this->assertTrue($badType['expects_reject']);
    }

    public function test_it_adds_structural_abuse_regardless_of_shape(): void
    {
        $labels = array_column($this->gen->generate(['a' => 1]), 'label');

        foreach (['body: null', 'body: empty object', 'body: array instead of object', 'body: deeply nested'] as $l) {
            $this->assertContains($l, $labels);
        }
    }

    public function test_it_is_bounded(): void
    {
        // A wide object would explode combinatorially; the generator caps it.
        $wide = [];
        for ($i = 0; $i < 40; $i++) {
            $wide["field{$i}"] = "value{$i}";
        }

        $this->assertLessThanOrEqual(FuzzGenerator::MAX_VARIANTS, count($this->gen->generate($wide)));
    }
}
