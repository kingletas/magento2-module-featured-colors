<?php
/**
 * FeaturedColor.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Model\ResourceModel;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Magento requires the _construct() initialiser, which trips PHPMD naming.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 */
class FeaturedColor extends AbstractDb
{
    public const string TABLE_NAME = 'commerce_featured_color';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, FeaturedColorInterface::FEATURED_COLOR_ID);
    }

    /**
     * Load the row for a product in a store scope, if there is one.
     *
     * @return array<string, mixed>|null
     */
    public function loadByProduct(int $productId, int $storeId = 0): ?array
    {
        $connection = $this->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->getMainTable())
                ->where('product_id = ?', $productId)
                ->where('store_id = ?', $storeId)
        );

        return $row === false ? null : $row;
    }

    /**
     * Load rows for many products at once.
     *
     * @param int[] $productIds
     * @return array<int, array<string, mixed>> Keyed by product id.
     */
    public function loadByProducts(array $productIds, int $storeId = 0): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $connection = $this->getConnection();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->getMainTable())
                ->where('product_id IN (?)', $productIds)
                ->where('store_id = ?', $storeId)
        );

        $byProduct = [];

        foreach ($rows as $row) {
            $byProduct[(int) $row['product_id']] = $row;
        }

        return $byProduct;
    }

    /**
     * Insert or update many rows in one statement.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return int Rows affected.
     */
    public function upsertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return (int) $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            $rows,
            [
                FeaturedColorInterface::CHILD_PRODUCT_ID,
                FeaturedColorInterface::COLOR_OPTION_ID,
                FeaturedColorInterface::COLOR_LABEL,
                FeaturedColorInterface::IMAGE_PATH,
                FeaturedColorInterface::UPDATED_AT,
            ]
        );
    }

    /**
     * Remove the featured colour for the given products.
     *
     * @param int[] $productIds
     *
     * @return int Rows removed.
     */
    public function deleteByProducts(array $productIds, ?int $storeId = null): int
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return 0;
        }

        $connection = $this->getConnection();
        $where = [$connection->quoteInto('product_id IN (?)', $productIds)];

        if ($storeId !== null) {
            $where[] = $connection->quoteInto('store_id = ?', $storeId);
        }

        return (int) $connection->delete($this->getMainTable(), implode(' AND ', $where));
    }
}
