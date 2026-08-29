<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Ui\DataProvider\Product\Form\Modifier;

use Commerce\FeaturedColors\Model\Config;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\Catalog\Api\Data\ProductInterface;
use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

/**
 * Puts the stored featured colour into the admin product form.
 */
class FeaturedColorField extends AbstractModifier
{
    public const string FIELD_NAME = 'featured_color';
    public const string FIELD_INPUT = 'featuredcolor';

    public function __construct(
        private readonly LocatorInterface $locator,
        private readonly FeaturedColorResource $resource,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    public function modifyData(array $data): array
    {
        $product = $this->locator->getProduct();
        $productId = (int) $product->getId();

        if ($productId === 0 || !$this->appliesTo($product)) {
            return $data;
        }

        $row = $this->resource->loadByProduct($productId, (int) $this->locator->getStore()->getId());

        if ($row === null) {
            // Fall back to the default scope, which is where a single-store
            // assignment lives.
            $row = $this->resource->loadByProduct($productId, 0);
        }

        return array_replace_recursive($data, [
            $productId => [
                self::FIELD_NAME => [
                    self::FIELD_INPUT => (string) ($row[FeaturedColorInterface::COLOR_LABEL] ?? ''),
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public function modifyMeta(array $meta): array
    {
        return $meta;
    }

    private function appliesTo(ProductInterface $product): bool
    {
        return $this->config->isEnabled() && $product->getTypeId() === Configurable::TYPE_CODE;
    }
}
