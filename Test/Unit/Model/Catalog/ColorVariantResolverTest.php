<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model\Catalog;

use Commerce\FeaturedColors\Model\Catalog\ColorVariantResolver;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * One query for a whole batch, and a deterministic winner within it.
 */
class ColorVariantResolverTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private ResourceConnection&MockObject $resourceConnection;

    /** @var array<int, array<string, string|null>> */
    private array $rows = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $wheres = [];

    /** @var array<int, string> */
    private array $joins = [];

    /** @var array<int, string> */
    private array $orders = [];

    private string $linkField = 'entity_id';
    private int $queries = 0;

    protected function setUp(): void
    {
        $this->rows = [];
        $this->wheres = [];
        $this->joins = [];
        $this->orders = [];
        $this->linkField = 'entity_id';
        $this->queries = 0;

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(static fn ($ident): string => '`' . $ident . '`');
        $this->connection->method('quoteInto')
            ->willReturnCallback(static fn (string $text, $value): string => str_replace('?', (string) $value, $text));
        $this->connection->method('select')->willReturnCallback(fn (): Select => $this->newSelect());
        $this->connection->method('fetchAll')->willReturnCallback(function (): array {
            $this->queries++;

            return $this->rows;
        });

        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => $table);
    }

    public function testAChildIsResolvedByParentAndColour(): void
    {
        $this->rows = [
            $this->row(parentId: 1, childId: 10, sku: 'SHIRT-NAVY-M', colorOptionId: 55, image: '/n/a/navy.jpg'),
        ];

        $resolved = $this->resolver()->resolveForParents([1]);

        $this->assertSame(
            [1 => [55 => ['child_id' => 10, 'sku' => 'SHIRT-NAVY-M', 'image_path' => '/n/a/navy.jpg']]],
            $resolved
        );
    }

    public function testAWholeBatchOfParentsCostsOneQuery(): void
    {
        $this->rows = [
            $this->row(1, 10, 'A-NAVY', 55),
            $this->row(2, 20, 'B-NAVY', 55),
            $this->row(3, 30, 'C-RED', 56),
        ];

        $resolved = $this->resolver()->resolveForParents([1, 2, 3]);

        $this->assertSame(1, $this->queries);
        $this->assertCount(3, $resolved);
    }

    /**
     * The determinism rule.
     */
    public function testTheLowestChildIdWinsWithinAColour(): void
    {
        $this->rows = [
            $this->row(1, 10, 'SHIRT-NAVY-S', 55),
            $this->row(1, 11, 'SHIRT-NAVY-M', 55),
            $this->row(1, 12, 'SHIRT-NAVY-L', 55),
        ];

        $resolved = $this->resolver()->resolveForParents([1]);

        $this->assertSame(10, $resolved[1][55]['child_id']);
    }

    public function testTheQueryOrdersByChildIdSoTheTieBreakIsTheDatabasesToo(): void
    {
        $this->resolver()->resolveForParents([1]);

        $this->assertContains('child.entity_id ASC', $this->orders);
    }

    /**
     * A disabled child must never become the face of a configurable.
     */
    public function testTheQueryExcludesDisabledChildrenButKeepsOnesWithNoStatusRow(): void
    {
        $this->resolver()->resolveForParents([1]);

        $conditions = array_map(static fn (array $where): string => $where[0], $this->wheres);

        $this->assertContains('status.value IS NULL OR status.value = ?', $conditions);
    }

    /**
     * Magento writes the literal "no_selection" rather than NULL when a product
     * has no image.
     */
    public function testNoSelectionIsNormalisedToNoImage(): void
    {
        $this->rows = [$this->row(1, 10, 'SHIRT-NAVY-M', 55, 'no_selection')];

        $this->assertNull($this->resolver()->resolveForParents([1])[1][55]['image_path']);
    }

    public function testAnAbsentImageIsNull(): void
    {
        $this->rows = [$this->row(1, 10, 'SHIRT-NAVY-M', 55, null)];

        $this->assertNull($this->resolver()->resolveForParents([1])[1][55]['image_path']);
    }

    public function testAnEmptyImageIsNull(): void
    {
        $this->rows = [$this->row(1, 10, 'SHIRT-NAVY-M', 55, '')];

        $this->assertNull($this->resolver()->resolveForParents([1])[1][55]['image_path']);
    }

    public function testTheJoinUsesEntityIdOnOpenSource(): void
    {
        $this->linkField = 'entity_id';

        $this->resolver()->resolveForParents([1]);

        $this->assertContains('link.parent_id = parent.`entity_id`', $this->joins);
    }

    /**
     * With content staging on, `catalog_product_super_link.parent_id`
     * references `row_id`.
     */
    public function testTheJoinUsesRowIdWhereContentStagingIsEnabled(): void
    {
        $this->linkField = 'row_id';

        $this->resolver()->resolveForParents([1]);

        $this->assertContains('link.parent_id = parent.`row_id`', $this->joins);
    }

    public function testAnEmptyBatchCostsNoQuery(): void
    {
        $this->assertSame([], $this->resolver()->resolveForParents([]));
        $this->assertSame(0, $this->queries);
    }

    public function testDuplicateParentIdsAreCollapsedBeforeQuerying(): void
    {
        $this->resolver()->resolveForParents([1, 1, 1]);

        $inWhere = array_values(array_filter(
            $this->wheres,
            static fn (array $where): bool => $where[0] === 'parent.entity_id IN (?)'
        ));

        $this->assertSame([1], $inWhere[0][1]);
    }

    public function testTheColourAttributeCodeIsAnArgument(): void
    {
        $eavConfig = $this->createMock(EavConfig::class);
        $codes = [];

        $eavConfig->method('getAttribute')->willReturnCallback(
            function (string $entity, string $code) use (&$codes): AbstractAttribute {
                $codes[] = $code;

                return $this->attribute(1);
            }
        );

        $this->resolver($eavConfig)->resolveForParents([1], 'colour_family');

        $this->assertContains('colour_family', $codes);
    }

    /**
     * @return array<string, string|null>
     */
    private function row(
        int $parentId,
        int $childId,
        string $sku,
        int $colorOptionId,
        ?string $image = null
    ): array {
        return [
            'parent_id' => (string) $parentId,
            'child_id' => (string) $childId,
            'sku' => $sku,
            'color_option_id' => (string) $colorOptionId,
            'image_path' => $image,
        ];
    }

    private function resolver(?EavConfig $eavConfig = null): ColorVariantResolver
    {
        if ($eavConfig === null) {
            $eavConfig = $this->createMock(EavConfig::class);
            $eavConfig->method('getAttribute')->willReturn($this->attribute(93));
        }

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturnCallback(fn (): string => $this->linkField);

        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')
            ->willReturnCallback(function (string $entity) use ($metadata): EntityMetadataInterface {
                $this->assertSame(ProductInterface::class, $entity);

                return $metadata;
            });

        return new ColorVariantResolver($this->resourceConnection, $metadataPool, $eavConfig);
    }

    private function attribute(int $id): AbstractAttribute
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn($id);

        return $attribute;
    }

    private function newSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnCallback(
            function ($name, $condition) use ($select): Select {
                $this->joins[] = (string) $condition;

                return $select;
            }
        );
        $select->method('joinLeft')->willReturnCallback(
            function ($name, $condition) use ($select): Select {
                $this->joins[] = (string) $condition;

                return $select;
            }
        );
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use ($select): Select {
                $this->wheres[] = [$condition, $value];

                return $select;
            }
        );
        $select->method('order')->willReturnCallback(
            function ($order) use ($select): Select {
                $this->orders[] = (string) $order;

                return $select;
            }
        );

        return $select;
    }
}
