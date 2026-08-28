<?php
/**
 * FormProduct.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Support;

use Magento\Catalog\Model\Product;

/**
 * The product a save observer is handed.
 *
 * @SuppressWarnings(PHPMD.MissingConstructor)
 */
class FormProduct extends Product
{
    /**
     * Untyped because `AbstractModel` declares it untyped, and redeclaring an
     * untyped parent property with a type is a fatal.
     *
     * @var array<string, mixed>
     */
    protected $_data = [];

    public function __construct()
    {
    }

    public function getSku()
    {
        return (string) $this->getData('sku');
    }
}
