<?php

namespace AgentPing\Laravel\Tests\Unit;

use AgentPing\Laravel\Support\Ids;
use PHPUnit\Framework\TestCase;

class IdsTest extends TestCase
{
    public function test_uuid7_hex_is_32_lowercase_hex_chars(): void
    {
        $hex = Ids::uuid7Hex();
        $this->assertSame(32, strlen($hex));
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $hex));
    }

    public function test_uuid7_version_bits_are_seven(): void
    {
        $hex = Ids::uuid7Hex();
        // Byte 6 (offset 12 in hex) high nibble should be 0x7.
        $this->assertSame('7', $hex[12]);
    }

    public function test_uuid7_is_monotonic(): void
    {
        $a = Ids::uuid7Hex();
        $b = Ids::uuid7Hex();
        $c = Ids::uuid7Hex();
        $this->assertLessThanOrEqual(0, strcmp($a, $b));
        $this->assertLessThanOrEqual(0, strcmp($b, $c));
    }

    public function test_extract_region_reads_two_letter_segment(): void
    {
        $this->assertSame('eu', Ids::extractRegion('apk_eu_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
        $this->assertSame('us', Ids::extractRegion('apk_us_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_extract_region_falls_back_to_eu_for_invalid(): void
    {
        $this->assertSame('eu', Ids::extractRegion(null));
        $this->assertSame('eu', Ids::extractRegion(''));
        $this->assertSame('eu', Ids::extractRegion('not-a-key'));
    }

    public function test_new_id_has_full_three_part_shape(): void
    {
        $id = Ids::newId('run', 'eu');
        $this->assertSame(1, preg_match('/^run_eu_[0-9a-f]{32}$/', $id));
        $this->assertTrue(Ids::isValid($id));
    }

    public function test_is_valid_rejects_garbage(): void
    {
        $this->assertFalse(Ids::isValid(''));
        $this->assertFalse(Ids::isValid(null));
        $this->assertFalse(Ids::isValid('run-eu-123'));
        $this->assertFalse(Ids::isValid('RUN_EU_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }
}
