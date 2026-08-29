<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Observer;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repoints a configurable's base image at its featured colour's image.
 */
class SyncBaseImage implements ObserverInterface
{
    public function __construct(
        private readonly ProductAction $productAction,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$observer->getEvent()->getData('sync_base_image')) {
            return;
        }

        $rows = $observer->getEvent()->getData('rows');

        if (!is_array($rows) || $rows === []) {
            return;
        }

        // Group by store and by image path so each distinct value is one
        // statement, rather than one statement per product.
        $byStoreAndImage = [];

        foreach ($rows as $row) {
            $imagePath = $row[FeaturedColorInterface::IMAGE_PATH] ?? null;
            $productId = (int) ($row[FeaturedColorInterface::PRODUCT_ID] ?? 0);

            if ($productId === 0 || !is_string($imagePath) || $imagePath === '') {
                continue;
            }

            $storeId = (int) ($row[FeaturedColorInterface::STORE_ID] ?? 0);
            $byStoreAndImage[$storeId][$imagePath][] = $productId;
        }

        foreach ($byStoreAndImage as $storeId => $byImage) {
            foreach ($byImage as $imagePath => $productIds) {
                $this->updateImage($productIds, (string) $imagePath, (int) $storeId);
            }
        }
    }

    /**
     * @param int[] $productIds
     */
    private function updateImage(array $productIds, string $imagePath, int $storeId): void
    {
        try {
            $this->productAction->updateAttributes(
                $productIds,
                [
                    'image' => $imagePath,
                    'small_image' => $imagePath,
                    'thumbnail' => $imagePath,
                ],
                $storeId
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Featured colours: could not repoint base images.',
                ['exception' => $e, 'store_id' => $storeId, 'product_count' => count($productIds)]
            );
        }
    }
}
