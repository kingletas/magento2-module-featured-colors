<?php
/**
 * CaptureFeaturedColorPlugin.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Plugin\Catalog\Adminhtml;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper;
use Magento\Framework\App\RequestInterface;

/**
 * Moves the admin form's featured-colour field onto the product being saved.
 */
class CaptureFeaturedColorPlugin
{
    /**
     * Data key the observer reads.
     */
    public const string DATA_KEY = 'commerce_featured_color';

    /**
     * Form field name posted by the product form modifier.
     */
    public const string REQUEST_KEY = 'featured_color';

    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterInitialize(
        Helper $subject,
        ProductInterface $product
    ): ProductInterface {
        $posted = $this->request->getParam(self::REQUEST_KEY);

        if (!is_array($posted)) {
            return $product;
        }

        $label = trim((string) ($posted['featuredcolor'] ?? ''));

        // An empty submission clears the assignment; absent means "not on this
        // form", which must leave the existing value alone.
        $product->setData(self::DATA_KEY, $label);

        return $product;
    }
}
