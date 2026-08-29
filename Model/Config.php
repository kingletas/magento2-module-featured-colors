<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Model;

use Commerce\Foundation\Model\Config\ModuleConfig;

class Config extends ModuleConfig
{
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('general/enabled', $storeId);
    }

    /**
     * Whether applying a featured colour also repoints the configurable's own
     * base image at the chosen child's image.
     */
    public function shouldSyncBaseImage(?int $storeId = null): bool
    {
        return $this->isSetFlag('general/sync_base_image', $storeId);
    }

    public function getColorAttributeCode(?int $storeId = null): string
    {
        return $this->getString('general/color_attribute', 'color', $storeId);
    }
}
