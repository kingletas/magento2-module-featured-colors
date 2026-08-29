<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Ui\DataProvider\Product;

use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Ui\DataProvider\AddFieldToCollectionInterface;

/**
 * Joins the featured colour onto the admin product grid.
 */
class AddFeaturedColorFieldToCollection implements AddFieldToCollectionInterface
{
    public const string TABLE_ALIAS = 'commerce_featured_color';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Untyped and unread: `AddFieldToCollectionInterface` declares both, and the
     * join is unconditional.
     *
     * @param string      $field
     * @param string|null $alias
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    public function addField(Collection $collection, $field, $alias = null): void
    {
        if (!$collection instanceof AbstractDb) {
            return;
        }

        $select = $collection->getSelect();

        // Idempotent: adding the same alias twice is a fatal SQL error.
        if (array_key_exists(self::TABLE_ALIAS, $select->getPart(\Magento\Framework\DB\Select::FROM))) {
            return;
        }

        $select->joinLeft(
            [self::TABLE_ALIAS => $this->resourceConnection->getTableName(FeaturedColorResource::TABLE_NAME)],
            sprintf(
                '%1$s.product_id = e.entity_id AND %1$s.store_id = %2$d',
                self::TABLE_ALIAS,
                // Admin grids are default-scope; joining every store's row here
                // is what produces duplicate rows.
                0
            ),
            ['featured_color_label' => self::TABLE_ALIAS . '.color_label']
        );
    }
}
