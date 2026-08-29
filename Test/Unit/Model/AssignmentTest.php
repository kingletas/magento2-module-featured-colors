<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model;

use Commerce\FeaturedColors\Model\Assignment;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class AssignmentTest extends TestCase
{
    public function testItCarriesTheSkuColourAndScope(): void
    {
        $assignment = new Assignment('SKU-1', 'Ceil Blue', 2, 47);

        $this->assertSame('SKU-1', $assignment->sku);
        $this->assertSame('Ceil Blue', $assignment->colorLabel);
        $this->assertSame(2, $assignment->storeId);
        $this->assertSame(47, $assignment->rowNumber);
    }

    /**
     * Default scope, so an assignment made outside a store context belongs to
     * every store.
     */
    public function testTheScopeDefaultsToTheDefaultStore(): void
    {
        $this->assertSame(0, (new Assignment('SKU-1', 'Ceil Blue'))->storeId);
    }

    /**
     * A row number only exists when the assignment came from a file.
     */
    public function testAnAssignmentThatDidNotComeFromAFileHasNoRowNumber(): void
    {
        $this->assertNull((new Assignment('SKU-1', 'Ceil Blue'))->rowNumber);
    }

    /**
     * Failures are reported by CSV row number, which an array index no longer
     * lines up with.
     */
    public function testTheRowNumberIsKeptSeparateFromTheBatchPosition(): void
    {
        $batch = [new Assignment('SKU-1', 'Blue', 0, 12), new Assignment('SKU-2', 'Red', 0, 40)];

        $this->assertSame(12, $batch[0]->rowNumber);
        $this->assertSame(40, $batch[1]->rowNumber);
    }

    public function testItIsImmutable(): void
    {
        foreach (['sku', 'colorLabel', 'storeId', 'rowNumber'] as $property) {
            $this->assertTrue(
                (new ReflectionProperty(Assignment::class, $property))->isReadOnly(),
                sprintf('%s must be read-only.', $property)
            );
        }
    }
}
