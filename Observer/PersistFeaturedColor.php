<?php
/**
 * PersistFeaturedColor.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Observer;

use Commerce\FeaturedColors\Model\Assignment;
use Commerce\FeaturedColors\Model\Config;
use Commerce\FeaturedColors\Model\FeaturedColorApplier;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\FeaturedColors\Plugin\Catalog\Adminhtml\CaptureFeaturedColorPlugin;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists a product's featured colour after it is saved.
 */
class PersistFeaturedColor implements ObserverInterface
{
    public function __construct(
        private readonly FeaturedColorApplier $applier,
        private readonly FeaturedColorResource $resource,
        private readonly MessageManagerInterface $messageManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $product = $observer->getEvent()->getData('product');

        if (!$product instanceof ProductInterface) {
            return;
        }

        // Absent key means the caller did not express an opinion; leave the
        // stored value alone.
        if (!$product->hasData(CaptureFeaturedColorPlugin::DATA_KEY)) {
            return;
        }

        // Only configurables have colour variants to feature.
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return;
        }

        $label = trim((string) $product->getData(CaptureFeaturedColorPlugin::DATA_KEY));
        $productId = (int) $product->getId();
        $storeId = (int) $product->getStoreId();

        try {
            if ($label === '') {
                $this->resource->deleteByProducts([$productId], $storeId);

                return;
            }

            $result = $this->applier->apply([
                new Assignment((string) $product->getSku(), $label, $storeId),
            ]);

            // The reason is surfaced in the admin rather than the save failing
            // silently.
            foreach ($result->errors as $message) {
                $this->messageManager->addWarningMessage((string) $message);
            }
        } catch (Throwable $e) {
            // Never let this abort the product save itself.
            $this->logger->error(
                'Featured colours: could not persist the featured colour for a product.',
                ['exception' => $e, 'product_id' => $productId]
            );
            $this->messageManager->addWarningMessage(
                __('The product was saved, but its featured colour could not be updated.')
            );
        }
    }
}
