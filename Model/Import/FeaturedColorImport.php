<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Model\Import;

use Commerce\FeaturedColors\Model\Assignment;
use Commerce\FeaturedColors\Model\FeaturedColorApplier;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\ImportExport\Helper\Data as ImportHelper;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\Entity\AbstractEntity;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ResourceModel\Helper;
use Magento\ImportExport\Model\ResourceModel\Import\Data;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Throwable;

/**
 * CSV import for featured colours.
 */
class FeaturedColorImport extends AbstractEntity
{
    public const string ENTITY_CODE = 'commerce_featured_colors';

    public const string COLUMN_SKU = 'sku';
    public const string COLUMN_COLOR = 'color';
    public const string COLUMN_STORE_ID = 'store_id';

    /**
     * Untyped because AbstractEntity declares it untyped: a typed redeclaration
     * is a PHP fatal.
     *
     * phpcs:disable SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
     *
     * @var bool
     */
    protected $needColumnCheck = true;

    /** @var bool */
    protected $logInHistory = true;

    /** @var string[] */
    protected $validColumnNames = [
        self::COLUMN_SKU,
        self::COLUMN_COLOR,
        self::COLUMN_STORE_ID,
    ];

    /** @var string[] */
    protected string $masterAttributeCode = self::COLUMN_SKU;

    public function __construct(
        ImportHelper $importExportData,
        Data $importData,
        Helper $resourceHelper,
        ProcessingErrorAggregatorInterface $errorAggregator,
        JsonHelper $jsonHelper,
        private readonly FeaturedColorApplier $applier,
        private readonly FeaturedColorResource $resource,
        private readonly ResourceConnection $resourceConnection
    ) {
        $this->_importExportData = $importExportData;
        $this->_resourceHelper = $resourceHelper;
        $this->_dataSourceModel = $importData;
        $this->errorAggregator = $errorAggregator;
        $this->jsonHelper = $jsonHelper;

        $this->initMessageTemplates();
    }

    public function getEntityTypeCode(): string
    {
        return self::ENTITY_CODE;
    }

    /**
     * @return string[]
     */
    public function getValidColumnNames(): array
    {
        return $this->validColumnNames;
    }

    /**
     * Structural validation only — no catalogue access.
     *
     * @param array<string, mixed> $rowData
     * @param int                  $rowNum
     *
     * phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    public function validateRow(array $rowData, $rowNum): bool
    {
        if (isset($this->_validatedRows[$rowNum])) {
            return !$this->getErrorAggregator()->isRowInvalid($rowNum);
        }

        $this->_validatedRows[$rowNum] = true;

        if (trim((string) ($rowData[self::COLUMN_SKU] ?? '')) === '') {
            $this->addRowError('SkuIsRequired', $rowNum);
        }

        // A colour is required on append and replace, but not on delete: a
        // delete row identifies the product, not the colour.
        if ($this->getBehavior() !== Import::BEHAVIOR_DELETE
            && trim((string) ($rowData[self::COLUMN_COLOR] ?? '')) === ''
        ) {
            $this->addRowError('ColorIsRequired', $rowNum);
        }

        $storeId = $rowData[self::COLUMN_STORE_ID] ?? '0';

        if ($storeId !== '' && $storeId !== null && !ctype_digit((string) $storeId)) {
            $this->addRowError('StoreIdMustBeNumeric', $rowNum);
        }

        return !$this->getErrorAggregator()->isRowInvalid($rowNum);
    }

    private function initMessageTemplates(): void
    {
        $this->addMessageTemplate('SkuIsRequired', __('The SKU column cannot be empty.'));
        $this->addMessageTemplate('ColorIsRequired', __('The colour column cannot be empty.'));
        $this->addMessageTemplate('StoreIdMustBeNumeric', __('The store_id column must be a whole number.'));
        $this->addMessageTemplate('AssignmentFailed', __('%1'));
    }

    protected function _importData(): bool
    {
        return match ($this->getBehavior()) {
            Import::BEHAVIOR_DELETE => $this->deleteEntities(),
            Import::BEHAVIOR_APPEND, Import::BEHAVIOR_REPLACE => $this->saveEntities(),
            default => false,
        };
    }

    private function saveEntities(): bool
    {
        $processed = 0;

        while ($bunch = $this->_dataSourceModel->getNextUniqueBunch($this->getIds())) {
            $assignments = [];

            foreach ($bunch as $rowNum => $row) {
                if (!$this->validateRow($row, $rowNum)) {
                    continue;
                }

                if ($this->getErrorAggregator()->hasToBeTerminated()) {
                    $this->getErrorAggregator()->addRowToSkip($rowNum);
                    continue;
                }

                $assignments[] = new Assignment(
                    trim((string) $row[self::COLUMN_SKU]),
                    trim((string) $row[self::COLUMN_COLOR]),
                    (int) ($row[self::COLUMN_STORE_ID] ?? 0),
                    (int) $rowNum
                );
            }

            if ($assignments === []) {
                continue;
            }

            try {
                $result = $this->applier->apply($assignments);
            } catch (Throwable $e) {
                // A failed bunch is reported and the import moves on, rather
                // than the whole run dying on one bad batch.
                $this->addRowError('AssignmentFailed', (int) array_key_first($bunch), null, $e->getMessage());
                continue;
            }

            // Errors are keyed by the row number that produced them, so the
            // admin sees the right line of the CSV.
            foreach ($result->errors as $rowNumber => $message) {
                $this->addRowError('AssignmentFailed', $rowNumber, null, (string) $message);
            }

            $processed += $result->applied;
        }

        return $processed > 0;
    }

    private function deleteEntities(): bool
    {
        $skusByStore = [];

        while ($bunch = $this->_dataSourceModel->getNextUniqueBunch($this->getIds())) {
            foreach ($bunch as $rowNum => $row) {
                if (!$this->validateRow($row, $rowNum)) {
                    continue;
                }

                // Collected as a list, not as `[$sku => $color]` followed by
                // `array_unique()`.
                $skusByStore[(int) ($row[self::COLUMN_STORE_ID] ?? 0)][] = trim((string) $row[self::COLUMN_SKU]);
            }
        }

        $removed = 0;

        foreach ($skusByStore as $storeId => $skus) {
            $productIds = $this->resolveProductIds($skus);

            if ($productIds !== []) {
                $removed += $this->resource->deleteByProducts($productIds, $storeId);
            }
        }

        return $removed > 0;
    }

    /**
     * @param string[] $skus
     *
     * @return int[]
     */
    private function resolveProductIds(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus)));

        if ($skus === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('sku IN (?)', $skus)
                ->where('type_id = ?', Configurable::TYPE_CODE)
        ));
    }
}
