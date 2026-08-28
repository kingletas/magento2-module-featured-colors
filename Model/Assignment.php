<?php
/**
 * Assignment.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Model;

/**
 * A request to make one colour the featured colour of one configurable.
 */
class Assignment
{
    public function __construct(
        public readonly string $sku,
        public readonly string $colorLabel,
        public readonly int $storeId = 0,
        public readonly ?int $rowNumber = null
    ) {
    }
}
