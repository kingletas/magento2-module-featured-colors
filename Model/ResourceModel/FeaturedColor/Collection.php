<?php
/**
 * Collection.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\FeaturedColor;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Magento requires the _construct() initialiser, which trips PHPMD naming.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 */
class Collection extends AbstractCollection
{
    /**
     * Set through the setter rather than by redeclaring the property.
     */
    protected function _construct(): void
    {
        $this->_setIdFieldName(FeaturedColorInterface::FEATURED_COLOR_ID);
        $this->_init(FeaturedColor::class, FeaturedColorResource::class);
    }
}
