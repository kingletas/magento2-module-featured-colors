<?php
/**
 * FeaturedColorImportTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model\Import;

use Commerce\FeaturedColors\Model\Assignment;
use Commerce\FeaturedColors\Model\AssignmentResult;
use Commerce\FeaturedColors\Model\FeaturedColorApplier;
use Commerce\FeaturedColors\Model\Import\FeaturedColorImport;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\ImportExport\Helper\Data as ImportHelper;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ResourceModel\Helper;
use Magento\ImportExport\Model\ResourceModel\Import\Data;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FeaturedColorImportTest extends TestCase
{
    /** @var array<int, array<int, array<string, mixed>>> Bunches handed out in order. */
    private array $bunches = [];

    /** @var array<int, array{code: string, row: int, message: string|null}> */
    private array $errors = [];

    /** @var Assignment[] */
    private array $applied = [];

    /** @var array<int, array{ids: int[], storeId: int|null}> */
    private array $deletes = [];

    /** @var int[] */
    private array $catalogueIds = [10, 11];

    /** @var array<int, array{condition: string, value: mixed}> */
    private array $conditions = [];

    private AssignmentResult $result;
    private ?RuntimeException $applyFailure = null;
    private int $bunchesTaken = 0;

    protected function setUp(): void
    {
        $this->bunches = [];
        $this->errors = [];
        $this->applied = [];
        $this->deletes = [];
        $this->conditions = [];
        $this->bunchesTaken = 0;
        $this->result = new AssignmentResult(1);
        $this->applyFailure = null;
    }

    public function testTheEntityCodeAndColumnsAreWhatTheCsvDeclares(): void
    {
        $import = $this->import();

        $this->assertSame(FeaturedColorImport::ENTITY_CODE, $import->getEntityTypeCode());
        $this->assertSame(
            [
                FeaturedColorImport::COLUMN_SKU,
                FeaturedColorImport::COLUMN_COLOR,
                FeaturedColorImport::COLUMN_STORE_ID,
            ],
            $import->getValidColumnNames()
        );
    }

    public function testAWellFormedRowValidates(): void
    {
        $this->assertTrue($this->import()->validateRow($this->row('SKU-1', 'Ceil Blue'), 1));
        $this->assertSame([], $this->errors);
    }

    /**
     * The whole rewrite is built on this: `validateRow()` does no loading.
     */
    public function testValidationNeverTouchesTheCatalogue(): void
    {
        $import = $this->import();

        foreach (range(1, 50) as $rowNum) {
            $import->validateRow($this->row('SKU-' . $rowNum, 'Ceil Blue'), $rowNum);
        }

        $this->assertSame([], $this->conditions);
        $this->assertSame([], $this->applied);
    }

    public function testARowWithNoSkuIsRejected(): void
    {
        $this->assertFalse($this->import()->validateRow($this->row('', 'Ceil Blue'), 1));
        $this->assertSame(['SkuIsRequired'], array_column($this->errors, 'code'));
    }

    public function testARowWithNoColourIsRejected(): void
    {
        $this->assertFalse($this->import()->validateRow($this->row('SKU-1', '   '), 1));
        $this->assertSame(['ColorIsRequired'], array_column($this->errors, 'code'));
    }

    /**
     * A delete row identifies the product, not the colour.
     */
    public function testADeleteRowNeedsNoColour(): void
    {
        $import = $this->import(Import::BEHAVIOR_DELETE);

        $this->assertTrue($import->validateRow($this->row('SKU-1', ''), 1));
        $this->assertSame([], $this->errors);
    }

    public function testANonNumericStoreIdIsRejected(): void
    {
        $this->assertFalse($this->import()->validateRow($this->row('SKU-1', 'Ceil Blue', 'default'), 1));
        $this->assertSame(['StoreIdMustBeNumeric'], array_column($this->errors, 'code'));
    }

    public function testAnAbsentStoreIdIsAccepted(): void
    {
        $import = $this->import();

        $this->assertTrue($import->validateRow(
            [FeaturedColorImport::COLUMN_SKU => 'SKU-1', FeaturedColorImport::COLUMN_COLOR => 'Ceil Blue'],
            1
        ));
    }

    /**
     * Magento calls `validateRow()` for the same row more than once - once in
     * the validation pass and again while importing.
     */
    public function testARowIsOnlyValidatedOnce(): void
    {
        $import = $this->import();

        $import->validateRow($this->row('', 'Ceil Blue'), 1);
        $import->validateRow($this->row('', 'Ceil Blue'), 1);

        $this->assertCount(1, $this->errors);
    }

    public function testEveryValidRowInABunchBecomesAnAssignment(): void
    {
        $this->bunches = [[
            1 => $this->row('SKU-1', 'Ceil Blue'),
            2 => $this->row('SKU-2', 'Navy', '2'),
        ]];

        $this->assertTrue($this->import()->importData());
        $this->assertCount(2, $this->applied);
        $this->assertSame('SKU-1', $this->applied[0]->sku);
        $this->assertSame(2, $this->applied[1]->storeId);
    }

    /**
     * Errors keyed by row number pass straight through, so the admin sees the
     * right CSV line.
     */
    public function testAnAssignmentFailureIsReportedAgainstItsOwnCsvRow(): void
    {
        $this->bunches = [[40 => $this->row('GONE', 'Ceil Blue')]];
        $this->result = new AssignmentResult(0, 0, [40 => __('No such SKU.')]);

        $this->import()->importData();

        $this->assertSame([40], array_column($this->errors, 'row'));
        $this->assertStringContainsString('No such SKU.', (string) $this->errors[0]['message']);
    }

    public function testTheRowNumberFromTheFileIsCarriedIntoTheAssignment(): void
    {
        $this->bunches = [[47 => $this->row('SKU-1', 'Ceil Blue')]];

        $this->import()->importData();

        $this->assertSame(47, $this->applied[0]->rowNumber);
    }

    public function testAnInvalidRowIsNotSentToTheApplier(): void
    {
        $this->bunches = [[
            1 => $this->row('', 'Ceil Blue'),
            2 => $this->row('SKU-1', 'Ceil Blue'),
        ]];

        $this->import()->importData();

        $this->assertCount(1, $this->applied);
        $this->assertSame('SKU-1', $this->applied[0]->sku);
    }

    /**
     * A failed bunch is reported and the import moves on rather than losing the
     * whole run.
     */
    public function testAFailingBunchIsReportedAndTheImportContinues(): void
    {
        $this->bunches = [
            [1 => $this->row('SKU-1', 'Ceil Blue')],
            [2 => $this->row('SKU-2', 'Navy')],
        ];
        $this->applyFailure = new RuntimeException('deadlock');

        $this->import()->importData();

        $this->assertSame(2, $this->bunchesTaken - 1);
        $this->assertCount(2, $this->errors);
        $this->assertSame(['AssignmentFailed', 'AssignmentFailed'], array_column($this->errors, 'code'));
    }

    public function testABunchWithNothingValidIsSkippedWithoutCallingTheApplier(): void
    {
        $this->bunches = [[1 => $this->row('', '')]];

        $this->assertFalse($this->import()->importData());
        $this->assertSame([], $this->applied);
    }

    public function testTheImportReportsWhetherAnythingWasWritten(): void
    {
        $this->bunches = [[1 => $this->row('SKU-1', 'Ceil Blue')]];
        $this->result = new AssignmentResult(0);

        $this->assertFalse($this->import()->importData());
    }

    public function testDeleteRemovesTheRowsForTheListedProducts(): void
    {
        $this->bunches = [[1 => $this->row('SKU-1', ''), 2 => $this->row('SKU-2', '')]];

        $this->assertTrue($this->import(Import::BEHAVIOR_DELETE)->importData());
        $this->assertSame([['ids' => [10, 11], 'storeId' => 0]], $this->deletes);
    }

    /**
     * Collected as a list: `array_unique()` compares values, so two SKUs
     * sharing a colour collapse.
     */
    public function testTwoSkusSharingAColourAreBothDeleted(): void
    {
        $this->bunches = [[
            1 => $this->row('SKU-1', 'Navy'),
            2 => $this->row('SKU-2', 'Navy'),
        ]];

        $this->import(Import::BEHAVIOR_DELETE)->importData();

        $this->assertSame(['SKU-1', 'SKU-2'], $this->conditions[0]['value']);
    }

    public function testDeleteIsScopedPerStore(): void
    {
        $this->bunches = [[
            1 => $this->row('SKU-1', '', '0'),
            2 => $this->row('SKU-2', '', '2'),
        ]];

        $this->import(Import::BEHAVIOR_DELETE)->importData();

        $this->assertSame([0, 2], array_column($this->deletes, 'storeId'));
    }

    /**
     * A featured colour only exists on a configurable, so a delete file naming
     * a simple product's SKU must not remove anything.
     */
    public function testDeleteOnlyResolvesConfigurables(): void
    {
        $this->bunches = [[1 => $this->row('SKU-1', '')]];

        $this->import(Import::BEHAVIOR_DELETE)->importData();

        $this->assertContains(
            ['condition' => 'type_id = ?', 'value' => Configurable::TYPE_CODE],
            $this->conditions
        );
    }

    public function testDeletingSkusThatResolveToNothingRemovesNothing(): void
    {
        $this->bunches = [[1 => $this->row('GONE', '')]];
        $this->catalogueIds = [];

        $this->assertFalse($this->import(Import::BEHAVIOR_DELETE)->importData());
        $this->assertSame([], $this->deletes);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $sku, string $color, string $storeId = '0'): array
    {
        return [
            FeaturedColorImport::COLUMN_SKU => $sku,
            FeaturedColorImport::COLUMN_COLOR => $color,
            FeaturedColorImport::COLUMN_STORE_ID => $storeId,
        ];
    }

    private function import(string $behavior = Import::BEHAVIOR_APPEND): FeaturedColorImport
    {
        $importData = $this->createMock(Data::class);
        $importData->method('getNextUniqueBunch')->willReturnCallback(
            fn (): array|bool => $this->bunches[$this->bunchesTaken++] ?? false
        );

        $applier = $this->createMock(FeaturedColorApplier::class);
        $applier->method('apply')->willReturnCallback(
            function (array $assignments): AssignmentResult {
                if ($this->applyFailure !== null) {
                    throw $this->applyFailure;
                }

                $this->applied = array_merge($this->applied, $assignments);

                return $this->result;
            }
        );

        $resource = $this->createMock(FeaturedColorResource::class);
        $resource->method('deleteByProducts')->willReturnCallback(
            function (array $ids, ?int $storeId = null): int {
                $this->deletes[] = ['ids' => $ids, 'storeId' => $storeId];

                return count($ids);
            }
        );

        $import = new FeaturedColorImport(
            $this->createMock(ImportHelper::class),
            $importData,
            $this->createMock(Helper::class),
            $this->errorAggregator(),
            $this->createMock(JsonHelper::class),
            $applier,
            $resource,
            $this->resourceConnection()
        );
        $import->setParameters(['behavior' => $behavior]);

        return $import;
    }

    private function errorAggregator(): ProcessingErrorAggregatorInterface&MockObject
    {
        $aggregator = $this->createMock(ProcessingErrorAggregatorInterface::class);
        $aggregator->method('addError')->willReturnCallback(
            function (
                $code,
                $level = null,
                $rowNumber = null,
                $columnName = null,
                $message = null
            ) use (&$aggregator) {
                $this->errors[] = ['code' => (string) $code, 'row' => (int) $rowNumber, 'message' => $message];

                return $aggregator;
            }
        );
        $aggregator->method('isRowInvalid')->willReturnCallback(
            fn ($rowNumber): bool => in_array((int) $rowNumber, array_column($this->errors, 'row'), true)
        );
        $aggregator->method('hasToBeTerminated')->willReturn(false);

        return $aggregator;
    }

    private function resourceConnection(): ResourceConnection&MockObject
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
        $connection->method('fetchCol')->willReturnCallback(fn (): array => $this->catalogueIds);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);

        return $resourceConnection;
    }
}
