<?php
/**
 * AssignmentResultTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model;

use Commerce\FeaturedColors\Model\AssignmentResult;
use PHPUnit\Framework\TestCase;

class AssignmentResultTest extends TestCase
{
    public function testItCountsWhatWasAppliedSkippedAndTouched(): void
    {
        $result = new AssignmentResult(3, 2, [], [10, 11, 12]);

        self::assertSame(3, $result->applied);
        self::assertSame(2, $result->skipped);
        self::assertSame([10, 11, 12], $result->touched);
    }

    /**
     * An empty batch produces an empty result rather than a null nobody can add
     * up, so a caller summing several batches needs no special case.
     */
    public function testAnEmptyResultIsAllZeroes(): void
    {
        $result = new AssignmentResult();

        self::assertSame(0, $result->applied);
        self::assertSame(0, $result->skipped);
        self::assertSame([], $result->errors);
        self::assertSame([], $result->touched);
        self::assertFalse($result->hasErrors());
    }

    /**
     * Errors are keyed by the CSV row that produced them, which is what lets
     * the import point the admin at the right line.
     */
    public function testErrorsAreKeyedByTheRowThatCausedThem(): void
    {
        $result = new AssignmentResult(0, 0, [12 => __('No such SKU.'), 40 => __('No such colour.')]);

        self::assertTrue($result->hasErrors());
        self::assertSame([12, 40], array_keys($result->errors));
    }

    /**
     * A batch can apply some rows and fail others; "applied 3" and "two errors"
     * are both true at once and neither implies the other.
     */
    public function testAPartlyAppliedBatchReportsBothHalves(): void
    {
        $result = new AssignmentResult(3, 0, [12 => __('No such SKU.')], [10]);

        self::assertSame(3, $result->applied);
        self::assertTrue($result->hasErrors());
    }
}
