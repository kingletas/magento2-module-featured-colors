<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Model;

use Magento\Framework\Phrase;

/**
 * Outcome of applying a batch of assignments.
 */
class AssignmentResult
{
    /**
     * @param int                          $applied  Assignments written.
     * @param int                          $skipped  Assignments already at the requested colour.
     * @param array<int, Phrase>           $errors   Row number => reason.
     * @param array<int, int>              $touched  Product ids whose row changed.
     */
    public function __construct(
        public readonly int $applied = 0,
        public readonly int $skipped = 0,
        public readonly array $errors = [],
        public readonly array $touched = []
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
