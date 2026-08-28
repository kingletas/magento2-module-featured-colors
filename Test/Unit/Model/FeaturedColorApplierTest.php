<?php
/**
 * FeaturedColorApplierTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\Assignment;
use Commerce\FeaturedColors\Model\Catalog\ColorOptionMap;
use Commerce\FeaturedColors\Model\Catalog\ColorVariantResolver;
use Commerce\FeaturedColors\Model\Config;
use Commerce\FeaturedColors\Model\FeaturedColorApplier;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\FeaturedColors\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\FeaturedColors\Test\Unit\Fake\RecordingLogger;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FeaturedColorApplierTest extends TestCase
{
    private const NOW = '2026-08-26 12:00:00';

    /** @var array<string, string> SKU as stored => entity id. */
    private array $catalogue = ['SKU-1' => '10', 'SKU-2' => '11'];

    /** @var array<int, array<int, array{child_id: int, image_path: string}>> */
    private array $variants = [10 => [77 => ['child_id' => 110, 'image_path' => '/c/e/ceil.jpg']]];

    /** @var array<int, array<string, mixed>> */
    private array $existing = [];

    /** @var array<string, int> */
    private array $optionsByLabel = ['Ceil Blue' => 77, 'Navy' => 78];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $upserts = [];

    /** @var array<int, array{name: string, data: array<string, mixed>}> */
    private array $events = [];

    /** @var string[] */
    private array $transactions = [];

    /** @var array<int, array{condition: string, value: mixed}> */
    private array $conditions = [];

    private RecordingLogger $logger;
    private FeaturedColorResource&MockObject $resource;
    private ColorVariantResolver&MockObject $variantResolver;
    private bool $syncBaseImage = false;

    protected function setUp(): void
    {
        $this->upserts = [];
        $this->events = [];
        $this->transactions = [];
        $this->conditions = [];
        $this->logger = new RecordingLogger();

        $this->resource = $this->createMock(FeaturedColorResource::class);
        $this->resource->method('loadByProducts')->willReturnCallback(fn (): array => $this->existing);
        $this->resource->method('upsertMany')->willReturnCallback(
            function (array $rows): int {
                $this->upserts[] = $rows;

                return count($rows);
            }
        );

        $this->variantResolver = $this->createMock(ColorVariantResolver::class);
        $this->variantResolver->method('resolveForParents')->willReturnCallback(fn (): array => $this->variants);
    }

    public function testAnAssignmentBecomesARowWithTheResolvedChildAndImage(): void
    {
        $result = $this->applier()->apply([new Assignment('SKU-1', 'Ceil Blue')]);

        self::assertSame(1, $result->applied);
        self::assertFalse($result->hasErrors());

        $row = $this->upserts[0][0];
        self::assertSame(10, $row[FeaturedColorInterface::PRODUCT_ID]);
        self::assertSame(110, $row[FeaturedColorInterface::CHILD_PRODUCT_ID]);
        self::assertSame(77, $row[FeaturedColorInterface::COLOR_OPTION_ID]);
        self::assertSame('/c/e/ceil.jpg', $row[FeaturedColorInterface::IMAGE_PATH]);
        self::assertSame(self::NOW, $row[FeaturedColorInterface::UPDATED_AT]);
        self::assertSame([10], $result->touched);
    }

    /**
     * A featured colour only means anything on a configurable, and resolving
     * variants for a simple product is work that cannot succeed.
     */
    public function testTheSkuLookupIsRestrictedToConfigurables(): void
    {
        $this->applier()->apply([new Assignment('SKU-1', 'Ceil Blue')]);

        self::assertContains(
            ['condition' => 'type_id = ?', 'value' => Configurable::TYPE_CODE],
            $this->conditions
        );
    }

    /**
     * SKU comparison is case-insensitive, in one place.
     */
    public function testAMixedCaseSkuStillMatchesTheCatalogue(): void
    {
        $result = $this->applier()->apply([new Assignment('sku-1', 'Ceil Blue')]);

        self::assertSame(1, $result->applied);
    }

    public function testASkuWithNoConfigurableIsReportedAgainstItsRow(): void
    {
        $result = $this->applier()->apply([new Assignment('GONE', 'Ceil Blue', 0, 42)]);

        self::assertSame(0, $result->applied);
        self::assertArrayHasKey(42, $result->errors);
        self::assertStringContainsString('GONE', (string) $result->errors[42]);
    }

    public function testAColourThatIsNotAnAttributeOptionIsReportedAgainstItsRow(): void
    {
        $result = $this->applier()->apply([new Assignment('SKU-1', 'Chartreuse', 0, 42)]);

        self::assertArrayHasKey(42, $result->errors);
        self::assertStringContainsString('Chartreuse', (string) $result->errors[42]);
    }

    public function testAConfigurableWithNoChildInThatColourIsReportedAgainstItsRow(): void
    {
        $result = $this->applier()->apply([new Assignment('SKU-1', 'Navy', 0, 42)]);

        self::assertArrayHasKey(42, $result->errors);
        self::assertStringContainsString('Navy', (string) $result->errors[42]);
    }

    /**
     * The row number comes from the CSV rather than from the position in the
     * batch.
     */
    public function testErrorsAreKeyedByTheCsvRowRatherThanTheBatchPosition(): void
    {
        $result = $this->applier()->apply([
            new Assignment('SKU-1', 'Ceil Blue', 0, 12),
            new Assignment('GONE', 'Ceil Blue', 0, 40),
        ]);

        self::assertSame([40], array_keys($result->errors));
    }

    /**
     * A row already holding the value is left alone, rather than costing an
     * update and a purge.
     */
    public function testAnAssignmentAlreadyAtThatColourIsSkipped(): void
    {
        $this->existing = [
            10 => [
                FeaturedColorInterface::COLOR_OPTION_ID => '77',
                FeaturedColorInterface::CHILD_PRODUCT_ID => '110',
            ],
        ];

        $result = $this->applier()->apply([new Assignment('SKU-1', 'Ceil Blue')]);

        self::assertSame(0, $result->applied);
        self::assertSame(1, $result->skipped);
        self::assertSame([], $this->upserts);
    }

    /**
     * A row whose child has changed is not unchanged, even when the colour is
     * the same.
     */
    public function testARowPointingAtADifferentChildIsRewritten(): void
    {
        $this->existing = [
            10 => [
                FeaturedColorInterface::COLOR_OPTION_ID => '77',
                FeaturedColorInterface::CHILD_PRODUCT_ID => '999',
            ],
        ];

        self::assertSame(1, $this->applier()->apply([new Assignment('SKU-1', 'Ceil Blue')])->applied);
    }

    /**
     * Rows are per store.
     */
    public function testEachStoreScopeIsResolvedAndWrittenSeparately(): void
    {
        $result = $this->applier()->apply([
            new Assignment('SKU-1', 'Ceil Blue', 0),
            new Assignment('SKU-1', 'Ceil Blue', 2),
        ]);

        self::assertSame(2, $result->applied);
        self::assertCount(2, $this->upserts);
        self::assertSame(0, $this->upserts[0][0][FeaturedColorInterface::STORE_ID]);
        self::assertSame(2, $this->upserts[1][0][FeaturedColorInterface::STORE_ID]);
    }

    /**
     * Merged key by key rather than with `+=`, which keeps the left-hand value
     * on collision.
     */
    public function testAnErrorFromALaterStoreScopeIsNotDropped(): void
    {
        $result = $this->applier()->apply([
            new Assignment('SKU-1', 'Ceil Blue', 0, 12),
            new Assignment('GONE', 'Ceil Blue', 2, 40),
        ]);

        self::assertSame([40], array_keys($result->errors));
    }

    /**
     * The stored label comes from the attribute option rather than from the
     * CSV.
     */
    public function testTheStoredLabelIsTheAttributesOwnWording(): void
    {
        $this->applier()->apply([new Assignment('SKU-1', 'ceil blue')]);

        self::assertSame('Ceil Blue', $this->upserts[0][0][FeaturedColorInterface::COLOR_LABEL]);
    }

    /**
     * The write commits before the event fires.
     */
    public function testTheBatchIsCommittedBeforeItIsAnnounced(): void
    {
        $this->applier()->apply([new Assignment('SKU-1', 'Ceil Blue')]);

        self::assertSame(['begin', 'commit'], $this->transactions);
        self::assertCount(1, $this->events);
        self::assertSame('commerce_featured_colors_applied', $this->events[0]['name']);
    }

    public function testTheEventCarriesTheRowsAndTheBaseImagePreference(): void
    {
        $this->syncBaseImage = true;

        $this->applier()->apply([new Assignment('SKU-1', 'Ceil Blue')]);

        self::assertTrue($this->events[0]['data']['sync_base_image']);
        self::assertCount(1, $this->events[0]['data']['rows']);
    }

    /**
     * A failed write is rolled back and nothing is announced: observers acting
     * on rows that no longer exist is worse than the failed import.
     */
    public function testAFailedWriteIsRolledBackAndNotAnnounced(): void
    {
        $this->resource = $this->createMock(FeaturedColorResource::class);
        $this->resource->method('loadByProducts')->willReturn([]);
        $this->resource->method('upsertMany')->willThrowException(new RuntimeException('deadlock'));

        try {
            $this->applier()->apply([new Assignment('SKU-1', 'Ceil Blue')]);
            self::fail('Expected the write failure to propagate.');
        } catch (RuntimeException) {
            // Expected: the import decides what to do about a failed bunch.
        }

        self::assertSame(['begin', 'rollback'], $this->transactions);
        self::assertSame([], $this->events);
        self::assertCount(1, $this->logger->errors);
    }

    /**
     * The whole batch fails together when the catalogue cannot be read at all -
     * reporting it per row would produce 5,000 copies of one message.
     */
    public function testAFailingVariantResolverFailsTheBatchOnce(): void
    {
        $this->variantResolver = $this->createMock(ColorVariantResolver::class);
        $this->variantResolver->method('resolveForParents')
            ->willThrowException(new RuntimeException('attribute missing'));

        $result = $this->applier()->apply([
            new Assignment('SKU-1', 'Ceil Blue', 0, 12),
            new Assignment('SKU-2', 'Ceil Blue', 0, 13),
        ]);

        self::assertCount(1, $result->errors);
        self::assertCount(1, $this->logger->errors);
        self::assertSame([], $this->upserts);
    }

    public function testAnEmptyBatchDoesNothing(): void
    {
        $result = $this->applier()->apply([]);

        self::assertSame(0, $result->applied);
        self::assertSame([], $this->upserts);
        self::assertSame([], $this->transactions);
    }

    /**
     * A batch where every row fails validation must not open a transaction or
     * announce an empty change set.
     */
    public function testABatchWithNothingToWriteIsNotCommittedOrAnnounced(): void
    {
        $this->applier()->apply([new Assignment('GONE', 'Ceil Blue', 0, 12)]);

        self::assertSame([], $this->transactions);
        self::assertSame([], $this->events);
    }

    /**
     * Two rows for the same product in one batch collapse to one write; a
     * second INSERT ...
     */
    public function testTwoAssignmentsForOneProductProduceOneRow(): void
    {
        $this->variants[10][78] = ['child_id' => 111, 'image_path' => '/n/a/navy.jpg'];

        $result = $this->applier()->apply([
            new Assignment('SKU-1', 'Ceil Blue'),
            new Assignment('SKU-1', 'Navy'),
        ]);

        self::assertCount(1, $this->upserts[0]);
        self::assertSame(111, $this->upserts[0][0][FeaturedColorInterface::CHILD_PRODUCT_ID]);
        self::assertSame(1, $result->applied);
    }

    private function applier(): FeaturedColorApplier
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use (&$select): Select {
                $this->conditions[] = ['condition' => $condition, 'value' => $value];

                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturnCallback(fn (): array => $this->catalogue);
        $connection->method('beginTransaction')->willReturnCallback(function () use (&$connection) {
            $this->transactions[] = 'begin';

            return $connection;
        });
        $connection->method('commit')->willReturnCallback(function () use (&$connection) {
            $this->transactions[] = 'commit';

            return $connection;
        });
        $connection->method('rollBack')->willReturnCallback(function () use (&$connection) {
            $this->transactions[] = 'rollback';

            return $connection;
        });

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);

        $colorOptionMap = $this->createMock(ColorOptionMap::class);
        $colorOptionMap->method('getAttributeCode')->willReturn('color');
        $colorOptionMap->method('findOptionIdByLabel')->willReturnCallback(
            fn (string $label): ?int => $this->optionsByLabel[ucwords(mb_strtolower(trim($label)))] ?? null
        );
        $colorOptionMap->method('findLabelByOptionId')->willReturnCallback(
            fn (int $optionId): ?string => array_search($optionId, $this->optionsByLabel, true) ?: null
        );

        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            function (string $name, array $data = []): void {
                $this->events[] = ['name' => $name, 'data' => $data];
            }
        );

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(self::NOW);

        $config = new Config(
            new ArrayScopeConfig([
                'test_featuredcolors/general/sync_base_image' => $this->syncBaseImage ? '1' : '0',
            ]),
            'test_featuredcolors'
        );

        return new FeaturedColorApplier(
            $resourceConnection,
            $this->resource,
            $colorOptionMap,
            $this->variantResolver,
            $eventManager,
            $dateTime,
            $config,
            $this->logger
        );
    }
}
