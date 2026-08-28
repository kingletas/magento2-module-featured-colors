<?php
/**
 * MigrateLegacyFeaturedColorsTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Setup\Patch\Data;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\FeaturedColors\Setup\Patch\Data\MigrateLegacyFeaturedColors;
use Commerce\FeaturedColors\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MigrateLegacyFeaturedColorsTest extends TestCase
{
    /** @var array<int, array<int, array<string, mixed>>> One entry per fetch, in order. */
    private array $pages = [];

    /** @var array<int, array{table: string, rows: array<int, array<string, mixed>>, update: string[]}> */
    private array $inserts = [];

    private bool $legacyTableExists = true;
    private int $fetches = 0;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->inserts = [];
        $this->fetches = 0;
        $this->logger = new RecordingLogger();
        $this->pages = [[$this->legacyRow(10, '{"label":"Ceil Blue","child_id":"110","url":"https://cdn/x.jpg"}')]];
    }

    public function testALegacyBlobBecomesRealColumns(): void
    {
        $this->patch()->apply();

        self::assertCount(1, $this->inserts);
        $row = $this->inserts[0]['rows'][0];
        self::assertSame(10, $row[FeaturedColorInterface::PRODUCT_ID]);
        self::assertSame(110, $row[FeaturedColorInterface::CHILD_PRODUCT_ID]);
        self::assertSame('Ceil Blue', $row[FeaturedColorInterface::COLOR_LABEL]);
    }

    public function testTheRowsGoIntoThisModulesOwnTable(): void
    {
        $this->patch()->apply();

        self::assertSame('pfx_' . FeaturedColorResource::TABLE_NAME, $this->inserts[0]['table']);
    }

    /**
     * The legacy value was a full CDN URL, and the `image` attribute expects a
     * media-gallery path.
     */
    public function testTheLegacyImageUrlIsDeliberatelyNotCarriedOver(): void
    {
        $this->patch()->apply();

        self::assertNull($this->inserts[0]['rows'][0][FeaturedColorInterface::IMAGE_PATH]);
    }

    /**
     * The legacy blob had no option id, and inventing one would be worse than
     * leaving the row to be recomputed.
     */
    public function testTheOptionIdIsLeftForTheNextApplyToResolve(): void
    {
        $this->patch()->apply();

        self::assertNull($this->inserts[0]['rows'][0][FeaturedColorInterface::COLOR_OPTION_ID]);
    }

    /**
     * A fresh install has no legacy table, and a data patch that throws there
     * blocks every later patch in the queue.
     */
    public function testAFreshInstallWithNoLegacyTableIsANoOp(): void
    {
        $this->legacyTableExists = false;

        $this->patch()->apply();

        self::assertSame([], $this->inserts);
        self::assertSame(0, $this->fetches);
    }

    /**
     * The legacy table name differed by deployment, so it is a di.xml argument
     * - and an empty one means "this store never had the old module".
     */
    public function testAnUnconfiguredLegacyTableSkipsTheMigrationEntirely(): void
    {
        $this->patch(legacyTable: '')->apply();

        self::assertSame(0, $this->fetches);
        self::assertSame([], $this->inserts);
    }

    /**
     * The blob column was named differently between deployments too.
     */
    public function testTheLegacyColumnNameIsConfigurable(): void
    {
        $this->pages = [[['product_id' => 10, 'store_id' => 0, 'payload' => '{"label":"Navy"}']]];

        $this->patch(legacyColumn: 'payload')->apply();

        self::assertSame('Navy', $this->inserts[0]['rows'][0][FeaturedColorInterface::COLOR_LABEL]);
    }

    /**
     * A single unparseable blob is a row somebody edited by hand years ago.
     */
    public function testAnUnparseableBlobIsSkippedAndTheRestMigrate(): void
    {
        $this->pages = [[
            $this->legacyRow(10, 'not json at all'),
            $this->legacyRow(11, '{"label":"Navy"}'),
        ]];

        $this->patch()->apply();

        self::assertCount(1, $this->inserts[0]['rows']);
        self::assertSame(11, $this->inserts[0]['rows'][0][FeaturedColorInterface::PRODUCT_ID]);
    }

    public function testAnEmptyBlobIsSkipped(): void
    {
        $this->pages = [[$this->legacyRow(10, ''), $this->legacyRow(11, '   ')]];

        $this->patch()->apply();

        self::assertSame([], $this->inserts);
    }

    /**
     * A blob that decodes to a scalar is not a record; accepting it would write
     * a row with no label and no child.
     */
    public function testABlobThatIsNotAnObjectIsSkipped(): void
    {
        $this->pages = [[$this->legacyRow(10, '"Ceil Blue"')]];

        $this->patch()->apply();

        self::assertSame([], $this->inserts);
    }

    /**
     * The legacy table can be very large.
     */
    public function testTheLegacyTableIsReadInPages(): void
    {
        $this->pages = [
            [$this->legacyRow(10, '{"label":"Ceil Blue"}')],
            [$this->legacyRow(11, '{"label":"Navy"}')],
            [],
        ];

        $this->patch()->apply();

        self::assertSame(3, $this->fetches);
        self::assertCount(2, $this->inserts);
    }

    /**
     * Only the value columns are updated on duplicate: a store that already ran
     * the new module must not have its rows re-keyed by the migration.
     */
    public function testOnlyTheValueColumnsAreUpdatedOnDuplicate(): void
    {
        $this->patch()->apply();

        self::assertSame(
            [FeaturedColorInterface::CHILD_PRODUCT_ID, FeaturedColorInterface::COLOR_LABEL],
            $this->inserts[0]['update']
        );
    }

    /**
     * A migration is run once, during an upgrade nobody watches.
     */
    public function testTheMigrationReportsHowManyRowsItMoved(): void
    {
        $this->patch()->apply();

        self::assertCount(1, $this->logger->infos);
        self::assertStringContainsString('1', $this->logger->infos[0]);
    }

    public function testApplyReturnsThePatchForChaining(): void
    {
        $patch = $this->patch();

        self::assertSame($patch, $patch->apply());
    }

    public function testThePatchDeclaresNoDependenciesOrAliasesOfItsOwn(): void
    {
        self::assertSame([], MigrateLegacyFeaturedColors::getDependencies());
        self::assertSame([], $this->patch()->getAliases());
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyRow(int $productId, string $blob): array
    {
        return ['product_id' => $productId, 'store_id' => 0, 'default' => $blob];
    }

    private function patch(
        string $legacyTable = 'legacy_featured_colors',
        string $legacyColumn = 'default'
    ): MigrateLegacyFeaturedColors {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('isTableExists')->willReturnCallback(fn (): bool => $this->legacyTableExists);
        $connection->method('fetchAll')->willReturnCallback(function (): array {
            return $this->pages[$this->fetches++] ?? [];
        });
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $rows, array $update = []): int {
                $this->inserts[] = ['table' => $table, 'rows' => $rows, 'update' => $update];

                return count($rows);
            }
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);

        return new MigrateLegacyFeaturedColors(
            $resourceConnection,
            new Json(),
            $this->logger,
            $legacyTable,
            $legacyColumn
        );
    }
}
