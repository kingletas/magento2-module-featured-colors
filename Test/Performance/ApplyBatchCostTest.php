<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Performance;

use Commerce\FeaturedColors\Model\Assignment;
use Commerce\FeaturedColors\Model\Catalog\ColorOptionMap;
use Commerce\FeaturedColors\Model\Catalog\ColorVariantResolver;
use Commerce\FeaturedColors\Model\Config;
use Commerce\FeaturedColors\Model\FeaturedColorApplier;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\Foundation\Test\Support\BudgetAssertions;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The applier's documented query budget, made checkable.
 */
class ApplyBatchCostTest extends TestCase
{
    use BudgetAssertions;

    private const OPTION_ID = 77;

    /** @var array<string, int> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
    }

    public function testApplyingABatchCostsTheSameWhateverItsSize(): void
    {
        $this->assertConstantCost(
            'round trips while applying featured colours',
            function (int $rows): int {
                $this->calls = [];
                $this->applier()->apply($this->assignments($rows));

                return array_sum($this->calls);
            },
            [1, 500]
        );
    }

    /**
     * Written out per query rather than totalled, so one saved and one added
     * still shows.
     */
    public function testTheBudgetIsTheOneTheClassDocuments(): void
    {
        $this->applier()->apply($this->assignments(500));

        $this->assertSame(
            [
                'sku lookup' => 1,
                'variant resolution' => 1,
                'existing rows' => 1,
                'write' => 1,
            ],
            $this->calls,
            'The class documents one query for each of these, for the whole batch.'
        );
    }

    /**
     * Batching is per store, because resolving two stores together would let
     * one overwrite the other.
     */
    public function testTwoStoresAreBatchedSeparately(): void
    {
        $assignments = [
            new Assignment('SKU-1', 'Ceil Blue', 1),
            new Assignment('SKU-1', 'Ceil Blue', 2),
        ];

        $this->applier()->apply($assignments);

        $this->assertSame(2, $this->calls['sku lookup'] ?? 0);
        $this->assertSame(2, $this->calls['write'] ?? 0);
    }

    /**
     * @return Assignment[]
     */
    private function assignments(int $rows): array
    {
        $assignments = [];

        for ($i = 1; $i <= $rows; $i++) {
            $assignments[] = new Assignment('SKU-' . $i, 'Ceil Blue', 1, $i);
        }

        return $assignments;
    }

    private function record(string $call): void
    {
        $this->calls[$call] = ($this->calls[$call] ?? 0) + 1;
    }

    private function applier(): FeaturedColorApplier
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturnCallback(function (): array {
            $this->record('sku lookup');

            // Every SKU asked for resolves, so nothing is filtered out before
            // reaching the parts of the class this measures.
            return $this->catalogue();
        });
        $connection->method('beginTransaction')->willReturnSelf();
        $connection->method('commit')->willReturnSelf();
        $connection->method('rollBack')->willReturnSelf();

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);

        $resource = $this->createMock(FeaturedColorResource::class);
        $resource->method('loadByProducts')->willReturnCallback(function (): array {
            $this->record('existing rows');

            return [];
        });
        $resource->method('upsertMany')->willReturnCallback(function (array $rows): int {
            $this->record('write');

            return count($rows);
        });

        $variantResolver = $this->createMock(ColorVariantResolver::class);
        $variantResolver->method('resolveForParents')->willReturnCallback(
            function (array $parentIds): array {
                $this->record('variant resolution');
                $variants = [];

                foreach ($parentIds as $parentId) {
                    $variants[(int) $parentId] = [
                        self::OPTION_ID => ['child_id' => (int) $parentId + 1000, 'image_path' => '/c/e/ceil.jpg'],
                    ];
                }

                return $variants;
            }
        );

        $colorOptionMap = $this->createMock(ColorOptionMap::class);
        $colorOptionMap->method('getAttributeCode')->willReturn('color');
        $colorOptionMap->method('findOptionIdByLabel')->willReturn(self::OPTION_ID);
        $colorOptionMap->method('findLabelByOptionId')->willReturn('Ceil Blue');

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-08-27 09:00:00');

        return new FeaturedColorApplier(
            $resourceConnection,
            $resource,
            $colorOptionMap,
            $variantResolver,
            $this->createMock(EventManagerInterface::class),
            $dateTime,
            new Config($this->scopeConfig([]), 'test_featuredcolors'),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return array<string, string> SKU => entity id, as the database returns it.
     */
    private function catalogue(): array
    {
        $catalogue = [];

        for ($i = 1; $i <= 500; $i++) {
            $catalogue['SKU-' . $i] = (string) (10 + $i);
        }

        return $catalogue;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return $scopeConfig;
    }
}
