<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Ui\Component\Listing\Column;

use Commerce\FeaturedColors\Ui\Component\Listing\Column\FeaturedColorLabel;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

class FeaturedColorLabelTest extends TestCase
{
    public function testTheStoredLabelIsRenderedUnderTheColumnsOwnName(): void
    {
        $item = $this->firstItem([['entity_id' => 10, 'featured_color_label' => 'Ceil Blue']]);

        $this->assertSame('Ceil Blue', $item['featured_color']);
    }

    /**
     * The label is a real column, so the raw row is left intact.
     */
    public function testTheSourceColumnIsLeftUntouched(): void
    {
        $item = $this->firstItem([['entity_id' => 10, 'featured_color_label' => 'Ceil Blue']]);

        $this->assertSame('Ceil Blue', $item['featured_color_label']);
    }

    /**
     * A product with no featured colour gets a dash, not an empty cell: an
     * empty cell in a grid reads as a rendering failure.
     */
    public function testAProductWithNoFeaturedColourShowsADash(): void
    {
        $this->assertSame('—', $this->firstItem([['entity_id' => 10]])['featured_color']);
        $this->assertSame(
            '—',
            $this->firstItem([['entity_id' => 10, 'featured_color_label' => '']])['featured_color']
        );
    }

    /**
     * The left join answers NULL for a product with no row, which is the
     * ordinary case on any catalogue where the feature is used selectively.
     */
    public function testANullFromTheLeftJoinIsHandled(): void
    {
        $item = $this->firstItem([['entity_id' => 10, 'featured_color_label' => null]]);

        $this->assertSame('—', $item['featured_color']);
    }

    public function testEveryRowIsRendered(): void
    {
        $items = $this->prepare([
            ['entity_id' => 10, 'featured_color_label' => 'Ceil Blue'],
            ['entity_id' => 11, 'featured_color_label' => 'Navy'],
        ])['data']['items'];

        $this->assertSame(['Ceil Blue', 'Navy'], array_column($items, 'featured_color'));
    }

    public function testADataSourceWithoutItemsIsReturnedUnchanged(): void
    {
        $column = $this->column();

        $this->assertSame([], $column->prepareDataSource([]));
        $this->assertSame(
            ['data' => ['items' => 'not-an-array']],
            $column->prepareDataSource(['data' => ['items' => 'not-an-array']])
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function prepare(array $items): array
    {
        return $this->column()->prepareDataSource(['data' => ['items' => $items]]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function firstItem(array $items): array
    {
        return $this->prepare($items)['data']['items'][0];
    }

    private function column(): FeaturedColorLabel
    {
        return new FeaturedColorLabel(
            $this->createMock(ContextInterface::class),
            $this->createMock(UiComponentFactory::class),
            [],
            ['name' => 'featured_color']
        );
    }
}
