<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Model;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\Catalog\ColorOptionMap;
use Commerce\FeaturedColors\Model\Catalog\ColorVariantResolver;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies featured-colour assignments in batches.
 */
class FeaturedColorApplier
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly FeaturedColorResource $resource,
        private readonly ColorOptionMap $colorOptionMap,
        private readonly ColorVariantResolver $variantResolver,
        private readonly EventManagerInterface $eventManager,
        private readonly DateTime $dateTime,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Assignment[] $assignments
     */
    public function apply(array $assignments): AssignmentResult
    {
        if ($assignments === []) {
            return new AssignmentResult();
        }

        // Group by store: rows are per-store, and resolving them together would
        // let one store's assignment overwrite another's.
        $byStore = [];

        foreach ($assignments as $assignment) {
            $byStore[$assignment->storeId][] = $assignment;
        }

        $applied = 0;
        $skipped = 0;
        $errors = [];
        $touchedPerStore = [];

        foreach ($byStore as $storeId => $storeAssignments) {
            $result = $this->applyForStore((int) $storeId, $storeAssignments);
            $applied += $result->applied;
            $skipped += $result->skipped;
            $touchedPerStore[] = $result->touched;

            // Assigned key by key rather than with `+=`, which would keep the
            // left-hand value on collision.
            foreach ($result->errors as $rowNumber => $message) {
                $errors[$rowNumber] = $message;
            }
        }

        $touched = array_merge(...$touchedPerStore);

        return new AssignmentResult($applied, $skipped, $errors, array_values(array_unique($touched)));
    }

    /**
     * @param Assignment[] $assignments
     */
    private function applyForStore(int $storeId, array $assignments): AssignmentResult
    {
        $errors = [];

        $idsBySku = $this->resolveConfigurableIds(array_map(
            static fn (Assignment $a): string => $a->sku,
            $assignments
        ));

        $variants = [];

        try {
            $variants = $this->variantResolver->resolveForParents(
                array_values($idsBySku),
                $this->colorOptionMap->getAttributeCode()
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Featured colours: could not resolve colour variants for a batch.',
                ['exception' => $e, 'count' => count($assignments)]
            );

            return new AssignmentResult(0, 0, [0 => __('Could not resolve colour variants; see the log.')]);
        }

        $existing = $this->resource->loadByProducts(array_values($idsBySku), $storeId);

        $rows = [];
        $touched = [];
        $skipped = 0;
        $now = $this->dateTime->gmtDate();

        foreach ($assignments as $assignment) {
            $rowKey = $assignment->rowNumber ?? count($errors);
            // SKU comparison is case-insensitive throughout, and in one place.
            $productId = $idsBySku[$this->normaliseSku($assignment->sku)] ?? null;

            if ($productId === null) {
                $errors[$rowKey] = __(
                    'No enabled configurable product exists with SKU "%1".',
                    $assignment->sku
                );
                continue;
            }

            $optionId = $this->findOptionId($assignment->colorLabel);

            if ($optionId === null) {
                $errors[$rowKey] = __(
                    'Colour "%1" is not an option on the %2 attribute.',
                    $assignment->colorLabel,
                    $this->colorOptionMap->getAttributeCode()
                );
                continue;
            }

            $variant = $variants[$productId][$optionId] ?? null;

            if ($variant === null) {
                $errors[$rowKey] = __(
                    'SKU "%1" has no enabled child product in colour "%2".',
                    $assignment->sku,
                    $assignment->colorLabel
                );
                continue;
            }

            if ($this->isUnchanged($existing[$productId] ?? null, $optionId, $variant['child_id'])) {
                $skipped++;
                continue;
            }

            $rows[$productId] = [
                FeaturedColorInterface::PRODUCT_ID => $productId,
                FeaturedColorInterface::STORE_ID => $storeId,
                FeaturedColorInterface::CHILD_PRODUCT_ID => $variant['child_id'],
                FeaturedColorInterface::COLOR_OPTION_ID => $optionId,
                FeaturedColorInterface::COLOR_LABEL => $this->colorOptionMap->findLabelByOptionId($optionId)
                    ?? $assignment->colorLabel,
                FeaturedColorInterface::IMAGE_PATH => $variant['image_path'],
                FeaturedColorInterface::UPDATED_AT => $now,
            ];
            $touched[] = $productId;
        }

        if ($rows === []) {
            return new AssignmentResult(0, $skipped, $errors);
        }

        $this->persist(array_values($rows));

        return new AssignmentResult(count($rows), $skipped, $errors, $touched);
    }

    /**
     * Write the batch atomically, then announce it.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function persist(array $rows): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $this->resource->upsertMany($rows);
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            $this->logger->error(
                'Featured colours: failed to persist a batch.',
                ['exception' => $e, 'count' => count($rows)]
            );

            throw $e;
        }

        $this->eventManager->dispatch(
            'commerce_featured_colors_applied',
            ['rows' => $rows, 'sync_base_image' => $this->config->shouldSyncBaseImage()]
        );
    }

    /**
     * Skip assignments that would write the value already stored.
     *
     * @param array<string, mixed>|null $existingRow
     */
    private function isUnchanged(?array $existingRow, int $optionId, int $childId): bool
    {
        if ($existingRow === null) {
            return false;
        }

        return (int) $existingRow[FeaturedColorInterface::COLOR_OPTION_ID] === $optionId
            && (int) $existingRow[FeaturedColorInterface::CHILD_PRODUCT_ID] === $childId;
    }

    private function findOptionId(string $label): ?int
    {
        try {
            return $this->colorOptionMap->findOptionIdByLabel($label);
        } catch (Throwable $e) {
            $this->logger->error(
                'Featured colours: could not read the colour attribute options.',
                ['exception' => $e]
            );

            return null;
        }
    }

    /**
     * One query, restricted to configurables.
     *
     * @param string[] $skus
     *
     * @return array<string, int> Normalised SKU => entity id.
     */
    private function resolveConfigurableIds(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(
            array_map(static fn (string $sku): string => trim($sku), $skus),
            static fn (string $sku): bool => $sku !== ''
        )));

        if ($skus === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('catalog_product_entity'),
                ['sku', 'entity_id']
            )
            ->where('sku IN (?)', $skus)
            // A featured colour only means anything on a configurable.
            ->where('type_id = ?', Configurable::TYPE_CODE);

        $byNormalisedSku = [];

        foreach ($connection->fetchPairs($select) as $sku => $entityId) {
            $byNormalisedSku[$this->normaliseSku((string) $sku)] = (int) $entityId;
        }

        return $byNormalisedSku;
    }

    private function normaliseSku(string $sku): string
    {
        return mb_strtolower(trim($sku));
    }
}
