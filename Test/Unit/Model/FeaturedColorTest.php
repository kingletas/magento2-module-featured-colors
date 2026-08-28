<?php
/**
 * FeaturedColorTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\FeaturedColor;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class FeaturedColorTest extends TestCase
{
    /**
     * Read off the declared name, because `getResourceName()` answers with
     * whatever was injected.
     */
    public function testTheEntityDeclaresItsOwnResourceModel(): void
    {
        $declared = (new ReflectionProperty(FeaturedColor::class, '_resourceName'))->getValue($this->entity());

        $this->assertSame(FeaturedColorResource::class, $declared);
    }

    public function testTheEntityIsKeyedOnTheFeaturedColorId(): void
    {
        $this->assertSame(FeaturedColorInterface::FEATURED_COLOR_ID, $this->entity()->getIdFieldName());
    }

    public function testEveryFieldRoundTripsThroughItsSetter(): void
    {
        $entity = $this->entity()
            ->setFeaturedColorId(1)
            ->setProductId(10)
            ->setStoreId(2)
            ->setChildProductId(11)
            ->setColorOptionId(77)
            ->setColorLabel('Ceil Blue')
            ->setImagePath('/c/e/ceil.jpg')
            ->setUpdatedAt('2026-08-26 12:00:00');

        $this->assertSame(1, $entity->getFeaturedColorId());
        $this->assertSame(10, $entity->getProductId());
        $this->assertSame(2, $entity->getStoreId());
        $this->assertSame(11, $entity->getChildProductId());
        $this->assertSame(77, $entity->getColorOptionId());
        $this->assertSame('Ceil Blue', $entity->getColorLabel());
        $this->assertSame('/c/e/ceil.jpg', $entity->getImagePath());
        $this->assertSame('2026-08-26 12:00:00', $entity->getUpdatedAt());
    }

    /**
     * The database hands back strings.
     */
    public function testTheNumericGettersCoerceWhatTheDatabaseHandsBack(): void
    {
        $entity = $this->entity();
        $entity->setData(FeaturedColorInterface::PRODUCT_ID, '10');
        $entity->setData(FeaturedColorInterface::STORE_ID, '2');
        $entity->setData(FeaturedColorInterface::CHILD_PRODUCT_ID, '11');
        $entity->setData(FeaturedColorInterface::COLOR_OPTION_ID, '77');

        $this->assertSame(10, $entity->getProductId());
        $this->assertSame(2, $entity->getStoreId());
        $this->assertSame(11, $entity->getChildProductId());
        $this->assertSame(77, $entity->getColorOptionId());
    }

    /**
     * The nullable columns stay nullable, so a row still needing recomputation
     * does not look complete.
     */
    public function testTheNullableFieldsStayNullRatherThanCoercingToZero(): void
    {
        $entity = $this->entity();

        $this->assertNull($entity->getFeaturedColorId());
        $this->assertNull($entity->getChildProductId());
        $this->assertNull($entity->getColorOptionId());
        $this->assertNull($entity->getColorLabel());
        $this->assertNull($entity->getImagePath());
        $this->assertNull($entity->getUpdatedAt());
    }

    public function testTheSettersAreFluent(): void
    {
        $entity = $this->entity();

        $this->assertSame($entity, $entity->setProductId(1));
        $this->assertSame($entity, $entity->setColorLabel(null));
    }

    private function entity(): FeaturedColor
    {
        $resource = $this->createMock(FeaturedColorResource::class);
        $resource->method('getIdFieldName')->willReturn(FeaturedColorInterface::FEATURED_COLOR_ID);

        return new FeaturedColor(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $resource
        );
    }
}
