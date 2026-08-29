<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model\ResourceModel\FeaturedColor;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\FeaturedColor;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The real constructor builds a SELECT through the object manager, which a unit
 * test does not have.
 */
class CollectionTest extends TestCase
{
    public function testTheCollectionIsWiredToTheEntityAndItsResource(): void
    {
        $collection = $this->collection();

        $this->assertSame(FeaturedColor::class, $collection->getModelName());
        $this->assertSame(FeaturedColorResource::class, $collection->getResourceModelName());
    }

    /**
     * Set through the setter: the parent declares `$_idFieldName` untyped.
     */
    public function testTheIdFieldIsSetThroughTheSetter(): void
    {
        $this->assertSame(FeaturedColorInterface::FEATURED_COLOR_ID, $this->collection()->getIdFieldName());
    }

    /**
     * The framework default is `id`, which is not this table's key - left
     * unset, every `getItemById()` looks up the wrong column.
     */
    public function testTheIdFieldIsNotTheFrameworkDefault(): void
    {
        $this->assertNotSame('id', $this->collection()->getIdFieldName());
    }

    private function collection(): Collection
    {
        $collection = (new ReflectionClass(Collection::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($collection, '_construct'))->invoke($collection);

        return $collection;
    }
}
