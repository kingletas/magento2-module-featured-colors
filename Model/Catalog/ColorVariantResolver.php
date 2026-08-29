<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Model\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Catalog\Model\Product as ProductEntity;

/**
 * Finds the enabled child of a configurable that carries a given colour.
 */
class ColorVariantResolver
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Resolve colour variants for many configurables at once.
     *
     * @param int[] $parentIds
     *
     * @return array<int, array<int, array{child_id: int, sku: string, image_path: string|null}>>
     *         Parent id => colour option id => child details.
     *
     * @throws LocalizedException
     */
    public function resolveForParents(array $parentIds, string $colorAttributeCode = 'color'): array
    {
        $parentIds = array_values(array_unique(array_map('intval', $parentIds)));

        if ($parentIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        return $this->index($connection->fetchAll($this->buildSelect($parentIds, $colorAttributeCode)));
    }

    /**
     * One query for the whole batch: every enabled child of every parent, with
     * its colour option and base image path.
     *
     * @param int[] $parentIds
     *
     * @throws LocalizedException
     */
    private function buildSelect(array $parentIds, string $colorAttributeCode): Select
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $linkTable = $this->resourceConnection->getTableName('catalog_product_super_link');
        $intTable = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');

        // entity_id on Open Source, row_id where content staging is enabled.
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $quotedLinkField = $connection->quoteIdentifier($linkField);

        $colorAttributeId = $this->getAttributeId($colorAttributeCode);
        $statusAttributeId = $this->getAttributeId(ProductInterface::STATUS);
        $imageAttributeId = $this->getAttributeId('image');

        return $connection->select()
            ->from(['parent' => $productTable], ['parent_id' => 'entity_id'])
            ->join(
                ['link' => $linkTable],
                sprintf('link.parent_id = parent.%s', $quotedLinkField),
                []
            )
            ->join(
                ['child' => $productTable],
                'child.entity_id = link.product_id',
                ['child_id' => 'entity_id', 'sku']
            )
            ->join(
                ['color' => $intTable],
                $connection->quoteInto(
                    sprintf(
                        'color.%s = child.%s AND color.attribute_id = ? AND color.store_id = 0',
                        $quotedLinkField,
                        $quotedLinkField
                    ),
                    $colorAttributeId
                ),
                ['color_option_id' => 'value']
            )
            ->joinLeft(
                ['status' => $intTable],
                $connection->quoteInto(
                    sprintf(
                        'status.%s = child.%s AND status.attribute_id = ? AND status.store_id = 0',
                        $quotedLinkField,
                        $quotedLinkField
                    ),
                    $statusAttributeId
                ),
                []
            )
            ->joinLeft(
                ['img' => $varcharTable],
                $connection->quoteInto(
                    sprintf(
                        'img.%s = child.%s AND img.attribute_id = ? AND img.store_id = 0',
                        $quotedLinkField,
                        $quotedLinkField
                    ),
                    $imageAttributeId
                ),
                ['image_path' => 'value']
            )
            ->where('parent.entity_id IN (?)', $parentIds)
            // A disabled child must never become the face of the configurable.
            ->where('status.value IS NULL OR status.value = ?', Status::STATUS_ENABLED)
            // Deterministic winner when a configurable has two enabled children
            // in the same colour (different sizes).
            ->order('child.entity_id ASC');
    }

    /**
     * First row per parent-and-colour wins, which the query's ordering has
     * already decided.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<int, array{child_id: int, sku: string, image_path: string|null}>>
     */
    private function index(array $rows): array
    {
        $resolved = [];

        foreach ($rows as $row) {
            $parentId = (int) $row['parent_id'];
            $optionId = (int) $row['color_option_id'];

            if (isset($resolved[$parentId][$optionId])) {
                continue;
            }

            $imagePath = (string) ($row['image_path'] ?? '');

            $resolved[$parentId][$optionId] = [
                'child_id' => (int) $row['child_id'],
                'sku' => (string) $row['sku'],
                // Magento writes the literal string "no_selection" rather than
                // NULL when a product has no image.
                'image_path' => ($imagePath === '' || $imagePath === 'no_selection') ? null : $imagePath,
            ];
        }

        return $resolved;
    }

    /**
     * @throws LocalizedException
     */
    private function getAttributeId(string $attributeCode): int
    {
        return (int) $this->eavConfig
            ->getAttribute(ProductEntity::ENTITY, $attributeCode)
            ->getAttributeId();
    }
}
