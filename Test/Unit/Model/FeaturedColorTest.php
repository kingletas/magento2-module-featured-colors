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

        self::assertSame(FeaturedColorResource::class, $declared);
    }

    public function testTheEntityIsKeyedOnTheFeaturedColorId(): void
    {
        self::assertSame(FeaturedColorInterface::FEATURED_COLOR_ID, $this->entity()->getIdFieldName());
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

        self::assertSame(1, $entity->getFeaturedColorId());
        self::assertSame(10, $entity->getProductId());
        self::assertSame(2, $entity->getStoreId());
        self::assertSame(11, $entity->getChildProductId());
        self::assertSame(77, $entity->getColorOptionId());
        self::assertSame('Ceil Blue', $entity->getColorLabel());
        self::assertSame('/c/e/ceil.jpg', $entity->getImagePath());
        self::assertSame('2026-08-26 12:00:00', $entity->getUpdatedAt());
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

        self::assertSame(10, $entity->getProductId());
        self::assertSame(2, $entity->getStoreId());
        self::assertSame(11, $entity->getChildProductId());
        self::assertSame(77, $entity->getColorOptionId());
    }

    /**
     * The nullable columns stay nullable, so a row still needing recomputation
     * does not look complete.
     */
    public function testTheNullableFieldsStayNullRatherThanCoercingToZero(): void
    {
        $entity = $this->entity();

        self::assertNull($entity->getFeaturedColorId());
        self::assertNull($entity->getChildProductId());
        self::assertNull($entity->getColorOptionId());
        self::assertNull($entity->getColorLabel());
        self::assertNull($entity->getImagePath());
        self::assertNull($entity->getUpdatedAt());
    }

    public function testTheSettersAreFluent(): void
    {
        $entity = $this->entity();

        self::assertSame($entity, $entity->setProductId(1));
        self::assertSame($entity, $entity->setColorLabel(null));
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
