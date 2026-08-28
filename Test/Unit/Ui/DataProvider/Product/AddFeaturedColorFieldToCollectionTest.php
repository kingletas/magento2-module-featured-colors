<?php
/**
 * AddFeaturedColorFieldToCollectionTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Ui\DataProvider\Product;

use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\FeaturedColors\Ui\DataProvider\Product\AddFeaturedColorFieldToCollection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AddFeaturedColorFieldToCollectionTest extends TestCase
{
    /** @var array<int, array{table: array<string, string>, on: string, columns: array<string, string>}> */
    private array $joins = [];

    /** @var array<string, mixed> */
    private array $fromPart = ['e' => []];

    private Select&MockObject $select;

    protected function setUp(): void
    {
        $this->joins = [];
        $this->fromPart = ['e' => []];

        $this->select = $this->createMock(Select::class);
        $this->select->method('getPart')->willReturnCallback(fn (): array => $this->fromPart);
        $this->select->method('joinLeft')->willReturnCallback(
            function (array $table, string $on, array $columns = []) use (&$select): Select {
                $this->joins[] = ['table' => $table, 'on' => $on, 'columns' => $columns];

                return $this->select;
            }
        );
    }

    public function testTheLabelIsJoinedOntoTheGrid(): void
    {
        $this->addField();

        $this->assertCount(1, $this->joins);
        $this->assertSame(
            ['featured_color_label' => AddFeaturedColorFieldToCollection::TABLE_ALIAS . '.color_label'],
            $this->joins[0]['columns']
        );
    }

    /**
     * The table name goes through the resource, so an installation's table
     * prefix is honoured.
     */
    public function testTheTableNameIsResolvedSoThePrefixIsApplied(): void
    {
        $this->addField();

        $this->assertSame(
            [AddFeaturedColorFieldToCollection::TABLE_ALIAS => 'pfx_' . FeaturedColorResource::TABLE_NAME],
            $this->joins[0]['table']
        );
    }

    /**
     * Joined on `product_id` alone, a product with several store rows would
     * repeat in the grid.
     */
    public function testTheJoinIsScopedToTheDefaultStore(): void
    {
        $this->addField();

        $this->assertStringContainsString('.store_id = 0', $this->joins[0]['on']);
        $this->assertStringContainsString('.product_id = e.entity_id', $this->joins[0]['on']);
    }

    /**
     * `addField()` runs once per field, and joining the same alias twice is
     * rejected by MySQL.
     */
    public function testJoiningTwiceUnderTheSameAliasIsRefused(): void
    {
        $collection = $this->collection();

        $this->field($collection);
        $this->fromPart[AddFeaturedColorFieldToCollection::TABLE_ALIAS] = [];
        $this->field($collection);

        $this->assertCount(1, $this->joins);
    }

    /**
     * The interface is declared over the base `Collection`, which has no
     * SELECT.
     */
    public function testACollectionWithNoSelectIsLeftAlone(): void
    {
        $collection = $this->createMock(Collection::class);

        (new AddFeaturedColorFieldToCollection($this->resourceConnection()))
            ->addField($collection, 'featured_color_label', null);

        $this->assertSame([], $this->joins);
    }

    private function addField(): void
    {
        $this->field($this->collection());
    }

    private function field(AbstractDb&MockObject $collection): void
    {
        (new AddFeaturedColorFieldToCollection($this->resourceConnection()))
            ->addField($collection, 'featured_color_label', null);
    }

    private function collection(): AbstractDb&MockObject
    {
        $collection = $this->createMock(AbstractDb::class);
        $collection->method('getSelect')->willReturn($this->select);

        return $collection;
    }

    private function resourceConnection(): ResourceConnection&MockObject
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);

        return $resourceConnection;
    }
}
