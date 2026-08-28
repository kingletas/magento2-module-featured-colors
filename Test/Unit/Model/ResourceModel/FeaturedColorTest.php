<?php
/**
 * FeaturedColorTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model\ResourceModel;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class FeaturedColorTest extends TestCase
{
    /** @var array<int, array{condition: string, value: mixed}> */
    private array $conditions = [];

    /** @var array<int, array{table: string, rows: array<int, array<string, mixed>>, update: string[]}> */
    private array $upserts = [];

    /** @var array<int, array{table: string, where: mixed}> */
    private array $deletes = [];

    /** @var array<string, mixed>|false */
    private array|false $row = false;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private AdapterInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->conditions = [];
        $this->upserts = [];
        $this->deletes = [];

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use (&$select): Select {
                $this->conditions[] = ['condition' => $condition, 'value' => $value];

                return $select;
            }
        );

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturnCallback(fn () => $this->row);
        $this->connection->method('fetchAll')->willReturnCallback(fn (): array => $this->rows);
        $this->connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string
                => str_replace('?', is_array($value) ? implode(',', $value) : (string) $value, $text)
        );
        $this->connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $rows, array $update = []): int {
                $this->upserts[] = ['table' => $table, 'rows' => $rows, 'update' => $update];

                return count($rows);
            }
        );
        $this->connection->method('delete')->willReturnCallback(
            function (string $table, $where = ''): int {
                $this->deletes[] = ['table' => $table, 'where' => $where];

                return 2;
            }
        );
    }

    public function testTheResourceIsWiredToItsTableAndKey(): void
    {
        $resource = (new ReflectionClass(FeaturedColor::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($resource, '_construct'))->invoke($resource);

        self::assertSame(
            FeaturedColor::TABLE_NAME,
            (new ReflectionProperty(FeaturedColor::class, '_mainTable'))->getValue($resource)
        );
        self::assertSame(FeaturedColorInterface::FEATURED_COLOR_ID, $resource->getIdFieldName());
    }

    /**
     * Rows are per store.
     */
    public function testASingleRowIsLookedUpByProductAndStore(): void
    {
        $this->row = ['product_id' => 10, 'color_label' => 'Ceil Blue'];

        self::assertSame(
            ['product_id' => 10, 'color_label' => 'Ceil Blue'],
            $this->resource()->loadByProduct(10, 2)
        );
        self::assertSame(
            [['condition' => 'product_id = ?', 'value' => 10], ['condition' => 'store_id = ?', 'value' => 2]],
            $this->conditions
        );
    }

    /**
     * `fetchRow` answers false for no match; passing that on would give every
     * caller a boolean where an array-or-null was declared.
     */
    public function testAProductWithNoRowIsNullRatherThanFalse(): void
    {
        $this->row = false;

        self::assertNull($this->resource()->loadByProduct(10, 0));
    }

    public function testManyProductsAreLoadedInOneQueryKeyedByProduct(): void
    {
        $this->rows = [
            ['product_id' => '10', 'color_label' => 'Ceil Blue'],
            ['product_id' => '11', 'color_label' => 'Navy'],
        ];

        $loaded = $this->resource()->loadByProducts([10, 11], 0);

        self::assertSame([10, 11], array_keys($loaded));
        self::assertSame('Navy', $loaded[11]['color_label']);
    }

    public function testTheBatchLookupIsAlsoScopedToTheStore(): void
    {
        $this->resource()->loadByProducts([10, 11], 2);

        self::assertSame(
            [
                ['condition' => 'product_id IN (?)', 'value' => [10, 11]],
                ['condition' => 'store_id = ?', 'value' => 2],
            ],
            $this->conditions
        );
    }

    public function testDuplicateProductIdsAreCollapsedBeforeQuerying(): void
    {
        $this->resource()->loadByProducts([10, 10, '10', 11], 0);

        self::assertSame([10, 11], $this->conditions[0]['value']);
    }

    /**
     * `IN ()` is a syntax error on MySQL, so an empty set has to be answered
     * before the query is built.
     */
    public function testAnEmptyProductSetIsAnsweredWithoutQuerying(): void
    {
        self::assertSame([], $this->resource()->loadByProducts([], 0));
        self::assertSame([], $this->conditions);
    }

    /**
     * One statement for the batch.
     */
    public function testTheWholeBatchIsWrittenInOneStatement(): void
    {
        $rows = [
            [FeaturedColorInterface::PRODUCT_ID => 10, FeaturedColorInterface::COLOR_LABEL => 'Ceil Blue'],
            [FeaturedColorInterface::PRODUCT_ID => 11, FeaturedColorInterface::COLOR_LABEL => 'Navy'],
        ];

        self::assertSame(2, $this->resource()->upsertMany($rows));
        self::assertCount(1, $this->upserts);
        self::assertSame($rows, $this->upserts[0]['rows']);
    }

    /**
     * The key columns stay out of the update list, or an upsert could move a
     * row's scope.
     */
    public function testOnlyTheValueColumnsAreUpdatedOnDuplicate(): void
    {
        $this->resource()->upsertMany([[FeaturedColorInterface::PRODUCT_ID => 10]]);

        self::assertNotContains(FeaturedColorInterface::PRODUCT_ID, $this->upserts[0]['update']);
        self::assertNotContains(FeaturedColorInterface::STORE_ID, $this->upserts[0]['update']);
        self::assertContains(FeaturedColorInterface::COLOR_LABEL, $this->upserts[0]['update']);
        self::assertContains(FeaturedColorInterface::IMAGE_PATH, $this->upserts[0]['update']);
    }

    public function testAnEmptyUpsertIsANoOp(): void
    {
        self::assertSame(0, $this->resource()->upsertMany([]));
        self::assertSame([], $this->upserts);
    }

    public function testRowsAreRemovedForTheGivenProductsInAStore(): void
    {
        self::assertSame(2, $this->resource()->deleteByProducts([10, 11], 2));
        self::assertStringContainsString('product_id IN (10,11)', $this->deletes[0]['where']);
        self::assertStringContainsString('store_id = 2', $this->deletes[0]['where']);
    }

    /**
     * A null store means every scope, which is what deleting a product's
     * featured colour must do.
     */
    public function testANullStoreRemovesTheProductsRowsInEveryScope(): void
    {
        $this->resource()->deleteByProducts([10], null);

        self::assertStringNotContainsString('store_id', $this->deletes[0]['where']);
    }

    public function testAnEmptyDeleteIsANoOp(): void
    {
        self::assertSame(0, $this->resource()->deleteByProducts([], 0));
        self::assertSame([], $this->deletes);
    }

    /**
     * Ids from a grid mass action are cast before they reach the query.
     */
    public function testProductIdsAreCoercedToIntegers(): void
    {
        $this->resource()->deleteByProducts(['10', '11'], 0);

        self::assertStringContainsString('product_id IN (10,11)', $this->deletes[0]['where']);
    }

    private function resource(): FeaturedColor&MockObject
    {
        $resource = $this->getMockBuilder(FeaturedColor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn(FeaturedColor::TABLE_NAME);

        return $resource;
    }
}
