<?php

namespace Tests\Unit;

use App\Services\Common\SortOrderValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SortOrderValidatorTest extends TestCase
{
    public function test_valid_complete_permutation_passes_validation(): void
    {
        $expected = ['file-1', 'file-2', 'file-3'];
        $submitted = ['file-3', 'file-1', 'file-2'];

        $this->assertTrue(SortOrderValidator::isValid($submitted, $expected));

        $inspection = SortOrderValidator::inspect($submitted, $expected);
        $this->assertTrue($inspection['is_valid']);
        $this->assertEmpty($inspection['missing']);
        $this->assertEmpty($inspection['duplicates']);
        $this->assertEmpty($inspection['unexpected']);
    }

    public function test_missing_ids_fail_validation(): void
    {
        $expected = ['file-1', 'file-2', 'file-3'];
        $submitted = ['file-2', 'file-1']; // Missing file-3

        $this->assertFalse(SortOrderValidator::isValid($submitted, $expected));

        $inspection = SortOrderValidator::inspect($submitted, $expected);
        $this->assertFalse($inspection['is_valid']);
        $this->assertEquals(['file-3'], $inspection['missing']);
    }

    public function test_duplicate_ids_fail_validation(): void
    {
        $expected = ['file-1', 'file-2', 'file-3'];
        $submitted = ['file-1', 'file-2', 'file-1']; // Duplicate file-1, missing file-3

        $this->assertFalse(SortOrderValidator::isValid($submitted, $expected));

        $inspection = SortOrderValidator::inspect($submitted, $expected);
        $this->assertFalse($inspection['is_valid']);
        $this->assertEquals(['file-1'], $inspection['duplicates']);
        $this->assertEquals(['file-3'], $inspection['missing']);
    }

    public function test_unexpected_ids_fail_validation(): void
    {
        $expected = ['file-1', 'file-2', 'file-3'];
        $submitted = ['file-1', 'file-2', 'file-999']; // file-999 is unexpected

        $this->assertFalse(SortOrderValidator::isValid($submitted, $expected));

        $inspection = SortOrderValidator::inspect($submitted, $expected);
        $this->assertFalse($inspection['is_valid']);
        $this->assertEquals(['file-999'], $inspection['unexpected']);
        $this->assertEquals(['file-3'], $inspection['missing']);
    }

    public function test_apply_strict_order_reorders_items_accurately(): void
    {
        $authoritative = [
            ['id' => 'a', 'name' => 'First'],
            ['id' => 'b', 'name' => 'Second'],
            ['id' => 'c', 'name' => 'Third'],
        ];

        $reordered = SortOrderValidator::applyStrictOrder($authoritative, ['c', 'a', 'b'], 'id');

        $this->assertCount(3, $reordered);
        $this->assertEquals('c', $reordered[0]['id']);
        $this->assertEquals('a', $reordered[1]['id']);
        $this->assertEquals('b', $reordered[2]['id']);
    }

    public function test_apply_strict_order_throws_on_tampered_payload(): void
    {
        $authoritative = [
            ['id' => 'a'],
            ['id' => 'b'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid reorder payload');

        SortOrderValidator::applyStrictOrder($authoritative, ['a', 'c'], 'id');
    }
}
