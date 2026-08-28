<?php
/**
 * FeaturedColourJourneyTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Behaviour;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\Assignment;
use Commerce\FeaturedColors\Model\Catalog\ColorOptionMap;
use Commerce\FeaturedColors\Model\Catalog\ColorVariantResolver;
use Commerce\FeaturedColors\Model\Config;
use Commerce\FeaturedColors\Model\FeaturedColorApplier;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\FeaturedColors\Observer\SyncBaseImage;
use Commerce\FeaturedColors\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\FeaturedColors\Test\Unit\Fake\RecordingLogger;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

/**
 * A merchandiser sets the colour a configurable leads with, and the listing
 * changes.
 */
class FeaturedColourJourneyTest extends TestCase
{
    private const SECTION = 'commerce_featuredcolors';
    private const NOW = '2026-08-27 09:00:00';
    private const CEIL_BLUE = 77;
    private const NAVY = 78;

    /** @var array<string, string> SKU => entity id, as the database holds it. */
    private array $catalogue = [];

    /** @var array<int, array<int, array{child_id: int, image_path: string}>> */
    private array $variants = [];

    /** @var array<int, array<string, mixed>> Rows already stored, by product id. */
    private array $stored = [];

    /** @var array<int, array<string, mixed>> Rows written, in order. */
    private array $written = [];

    /** @var array<int, array{ids: int[], attributes: array<string, string>, store: int}> */
    private array $imageUpdates = [];

    /** @var string[] Transaction calls, in order. */
    private array $transactions = [];

    /** @var array<string, string> */
    private array $settings = [];

    private bool $writeFails = false;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->catalogue = ['SCRUB-TOP' => '10', 'SCRUB-TROUSER' => '11'];
        $this->variants = [
            10 => [
                self::CEIL_BLUE => ['child_id' => 110, 'image_path' => '/c/e/ceil.jpg'],
                self::NAVY => ['child_id' => 111, 'image_path' => '/n/a/navy.jpg'],
            ],
            11 => [
                self::CEIL_BLUE => ['child_id' => 120, 'image_path' => '/c/e/ceil-trouser.jpg'],
            ],
        ];
        $this->stored = [];
        $this->written = [];
        $this->imageUpdates = [];
        $this->transactions = [];
        $this->writeFails = false;
        $this->logger = new RecordingLogger();
        $this->settings = [
            self::SECTION . '/general/enabled' => '1',
            self::SECTION . '/general/sync_base_image' => '1',
        ];
    }

    public function testAssigningAColourRepointsTheProductsListingImage(): void
    {
        $result = $this->assign(new Assignment('SCRUB-TOP', 'Ceil Blue', 1));

        $this->assertSame(1, $result->applied);
        $this->assertSame(110, $this->written[0][FeaturedColorInterface::CHILD_PRODUCT_ID]);
        $this->assertSame(
            [['ids' => [10], 'attributes' => $this->imageAttributes('/c/e/ceil.jpg'), 'store' => 1]],
            $this->imageUpdates
        );
    }

    /**
     * A rolled-back batch must leave nothing behind - not a row, and not a
     * listing image pointing at a variant the table says was never featured.
     */
    public function testAFailedWriteRepointsNoImages(): void
    {
        $this->writeFails = true;

        try {
            $this->assign(new Assignment('SCRUB-TOP', 'Ceil Blue', 1));
            $this->fail('The write failure should have propagated.');
        } catch (\RuntimeException) {
            // The applier logs and rethrows; the caller decides.
        }

        $this->assertSame(['begin', 'rollback'], $this->transactions);
        $this->assertSame([], $this->imageUpdates, 'Nothing was committed, so nothing should have been repointed.');
    }

    /**
     * The flag is read by the applier and honoured by the observer, so off
     * means off in both.
     */
    public function testAStoreThatOptedOutOfImageSyncingKeepsItsImages(): void
    {
        $this->settings[self::SECTION . '/general/sync_base_image'] = '0';

        $result = $this->assign(new Assignment('SCRUB-TOP', 'Ceil Blue', 1));

        $this->assertSame(1, $result->applied, 'The row is still written.');
        $this->assertSame([], $this->imageUpdates, 'And the images are left alone.');
    }

    /**
     * The observer groups by store and by image path, so a catalogue-wide
     * assignment is a handful of statements rather than one per product.
     */
    public function testProductsSharingAnImageAreRepointedTogether(): void
    {
        $this->assign(
            new Assignment('SCRUB-TOP', 'Ceil Blue', 1),
            new Assignment('SCRUB-TROUSER', 'Ceil Blue', 1)
        );

        $this->assertCount(2, $this->imageUpdates, 'Two distinct image paths, two statements.');
    }

    /**
     * A re-run of the same import is the common case - a nightly feed sends the
     * whole file.
     */
    public function testReassigningTheColourAProductAlreadyHasIsANoOp(): void
    {
        $this->stored = [
            10 => [
                FeaturedColorInterface::COLOR_OPTION_ID => self::CEIL_BLUE,
                FeaturedColorInterface::CHILD_PRODUCT_ID => 110,
            ],
        ];

        $result = $this->assign(new Assignment('SCRUB-TOP', 'Ceil Blue', 1));

        $this->assertSame(0, $result->applied);
        $this->assertSame(1, $result->skipped);
        $this->assertSame([], $this->imageUpdates);
        $this->assertSame([], $this->transactions, 'A no-op batch should not open a transaction at all.');
    }

    /**
     * An import file is written by a person, and the useful failure is "row 4
     * names a colour SCRUB-TROUSER does not come in" rather than a count.
     */
    public function testAColourAProductDoesNotComeInIsReportedAgainstItsRow(): void
    {
        $result = $this->assign(
            new Assignment('SCRUB-TOP', 'Ceil Blue', 1, 3),
            new Assignment('SCRUB-TROUSER', 'Navy', 1, 4)
        );

        $this->assertSame(1, $result->applied);
        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey(4, $result->errors);
        $this->assertStringContainsString('SCRUB-TROUSER', (string) $result->errors[4]);
    }

    public function testASkuThatDoesNotExistIsAnErrorAgainstItsRowAlone(): void
    {
        $result = $this->assign(
            new Assignment('SCRUB-TOP', 'Ceil Blue', 1, 1),
            new Assignment('NOT-A-SKU', 'Ceil Blue', 1, 2)
        );

        $this->assertSame(1, $result->applied);
        $this->assertArrayHasKey(2, $result->errors);
    }

    /**
     * Featured colours are per store, and each store's assignments resolve on
     * their own.
     */
    public function testTwoStoresKeepTheirOwnFeaturedColour(): void
    {
        $result = $this->assign(
            new Assignment('SCRUB-TOP', 'Ceil Blue', 1),
            new Assignment('SCRUB-TOP', 'Navy', 2)
        );

        $this->assertSame(2, $result->applied);

        $byStore = [];

        foreach ($this->written as $row) {
            $byStore[$row[FeaturedColorInterface::STORE_ID]] = $row[FeaturedColorInterface::COLOR_OPTION_ID];
        }

        $this->assertSame([1 => self::CEIL_BLUE, 2 => self::NAVY], $byStore);
    }

    /**
     * Apply the assignments, with the observer standing behind the event the
     * way `events.xml` puts it there.
     */
    private function assign(Assignment ...$assignments): \Commerce\FeaturedColors\Model\AssignmentResult
    {
        return $this->applier()->apply($assignments);
    }

    private function applier(): FeaturedColorApplier
    {
        $config = new Config(new ArrayScopeConfig($this->settings), self::SECTION);

        return new FeaturedColorApplier(
            $this->resourceConnection(),
            $this->resource(),
            $this->colorOptionMap(),
            $this->variantResolver(),
            $this->eventManager(),
            $this->dateTime(),
            $config,
            $this->logger
        );
    }

    /**
     * An event manager carrying the one observer `etc/events.xml` declares.
     */
    private function eventManager(): EventManagerInterface
    {
        $syncBaseImage = new SyncBaseImage($this->productAction(), $this->logger);

        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            static function (string $name, array $data = []) use ($syncBaseImage): void {
                if ($name !== 'commerce_featured_colors_applied') {
                    return;
                }

                $observer = new Observer();
                $observer->setEvent(new Event($data));
                $syncBaseImage->execute($observer);
            }
        );

        return $eventManager;
    }

    private function productAction(): ProductAction
    {
        $action = $this->createMock(ProductAction::class);
        $action->method('updateAttributes')->willReturnCallback(
            function (array $productIds, array $attributes, $storeId): void {
                $this->imageUpdates[] = [
                    'ids' => array_values($productIds),
                    'attributes' => $attributes,
                    'store' => (int) $storeId,
                ];
            }
        );

        return $action;
    }

    private function resource(): FeaturedColorResource
    {
        $resource = $this->createMock(FeaturedColorResource::class);
        $resource->method('loadByProducts')->willReturnCallback(fn (): array => $this->stored);
        $resource->method('upsertMany')->willReturnCallback(
            function (array $rows): int {
                if ($this->writeFails) {
                    throw new \RuntimeException('the write failed');
                }

                foreach ($rows as $row) {
                    $this->written[] = $row;
                }

                return count($rows);
            }
        );

        return $resource;
    }

    private function resourceConnection(): ResourceConnection
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

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
        $resourceConnection->method('getTableName')->willReturnCallback(static fn (string $t): string => 'pfx_' . $t);

        return $resourceConnection;
    }

    private function colorOptionMap(): ColorOptionMap
    {
        $options = ['Ceil Blue' => self::CEIL_BLUE, 'Navy' => self::NAVY];

        $map = $this->createMock(ColorOptionMap::class);
        $map->method('getAttributeCode')->willReturn('color');
        $map->method('findOptionIdByLabel')->willReturnCallback(
            static fn (string $label): ?int => $options[ucwords(mb_strtolower(trim($label)))] ?? null
        );
        $map->method('findLabelByOptionId')->willReturnCallback(
            static fn (int $optionId): ?string => array_search($optionId, $options, true) ?: null
        );

        return $map;
    }

    private function variantResolver(): ColorVariantResolver
    {
        $resolver = $this->createMock(ColorVariantResolver::class);
        $resolver->method('resolveForParents')->willReturnCallback(
            function (array $parentIds): array {
                $wanted = array_flip(array_map('intval', $parentIds));

                return array_intersect_key($this->variants, $wanted);
            }
        );

        return $resolver;
    }

    private function dateTime(): DateTime
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(self::NOW);

        return $dateTime;
    }

    /**
     * @return array<string, string>
     */
    private function imageAttributes(string $path): array
    {
        return ['image' => $path, 'small_image' => $path, 'thumbnail' => $path];
    }
}
