<?php
/**
 * FeaturedColorFieldTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Ui\DataProvider\Product\Form\Modifier;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\Config;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\FeaturedColors\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\FeaturedColors\Test\Unit\Fake\FormProduct;
use Commerce\FeaturedColors\Ui\DataProvider\Product\Form\Modifier\FeaturedColorField;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeaturedColorFieldTest extends TestCase
{
    /** @var array<string, array<string, mixed>|null> Keyed by "productId:storeId". */
    private array $rows = [];

    /** @var array<int, array{productId: int, storeId: int}> */
    private array $lookups = [];

    private int $currentStoreId = 0;
    private FormProduct $product;

    protected function setUp(): void
    {
        $this->rows = [];
        $this->lookups = [];
        $this->currentStoreId = 0;

        $this->product = new FormProduct();
        $this->product->setData('entity_id', 10);
        $this->product->setData('type_id', Configurable::TYPE_CODE);
    }

    public function testTheStoredLabelIsPutIntoTheForm(): void
    {
        $this->rows['10:0'] = [FeaturedColorInterface::COLOR_LABEL => 'Ceil Blue'];

        $data = $this->modifier()->modifyData([]);

        $this->assertSame(
            'Ceil Blue',
            $data[10][FeaturedColorField::FIELD_NAME][FeaturedColorField::FIELD_INPUT]
        );
    }

    /**
     * The query is store-scoped, or a multi-store catalogue shows an arbitrary
     * store's colour.
     */
    public function testTheValueIsReadForTheStoreBeingEdited(): void
    {
        $this->currentStoreId = 2;
        $this->rows['10:2'] = [FeaturedColorInterface::COLOR_LABEL => 'Navy'];
        $this->rows['10:0'] = [FeaturedColorInterface::COLOR_LABEL => 'Ceil Blue'];

        $data = $this->modifier()->modifyData([]);

        $this->assertSame('Navy', $data[10][FeaturedColorField::FIELD_NAME][FeaturedColorField::FIELD_INPUT]);
        $this->assertSame([['productId' => 10, 'storeId' => 2]], $this->lookups);
    }

    /**
     * A single-store assignment lives in the default scope, so a store view
     * with no override of its own has to fall back rather than show blank.
     */
    public function testAStoreWithNoRowFallsBackToTheDefaultScope(): void
    {
        $this->currentStoreId = 2;
        $this->rows['10:0'] = [FeaturedColorInterface::COLOR_LABEL => 'Ceil Blue'];

        $data = $this->modifier()->modifyData([]);

        $this->assertSame('Ceil Blue', $data[10][FeaturedColorField::FIELD_NAME][FeaturedColorField::FIELD_INPUT]);
        $this->assertSame([2, 0], array_column($this->lookups, 'storeId'));
    }

    public function testAProductWithNoAssignmentGetsAnEmptyField(): void
    {
        $data = $this->modifier()->modifyData([]);

        $this->assertSame('', $data[10][FeaturedColorField::FIELD_NAME][FeaturedColorField::FIELD_INPUT]);
    }

    /**
     * Merged rather than replaced, so the other modifiers' contributions
     * survive.
     */
    public function testTheExistingFormDataIsPreserved(): void
    {
        $this->rows['10:0'] = [FeaturedColorInterface::COLOR_LABEL => 'Ceil Blue'];

        $data = $this->modifier()->modifyData([10 => ['product' => ['name' => 'Scrub Top']]]);

        $this->assertSame('Scrub Top', $data[10]['product']['name']);
        $this->assertSame('Ceil Blue', $data[10][FeaturedColorField::FIELD_NAME][FeaturedColorField::FIELD_INPUT]);
    }

    /**
     * The new-product form has no id to key the data by, and no stored value to
     * look up.
     */
    public function testAnUnsavedProductIsLeftAloneWithoutQuerying(): void
    {
        $this->product = new FormProduct();
        $this->product->setData('type_id', Configurable::TYPE_CODE);

        $this->assertSame([], $this->modifier()->modifyData([]));
        $this->assertSame([], $this->lookups);
    }

    public function testASimpleProductIsLeftAloneWithoutQuerying(): void
    {
        $this->product->setData('type_id', 'simple');

        $this->assertSame([], $this->modifier()->modifyData([]));
        $this->assertSame([], $this->lookups);
    }

    public function testADisabledFeatureLeavesTheFormAloneWithoutQuerying(): void
    {
        $this->assertSame([], $this->modifier(enabled: false)->modifyData([]));
        $this->assertSame([], $this->lookups);
    }

    /**
     * The field's own metadata comes from the ui_component XML; this modifier
     * only supplies the value, and rewriting the meta here would fight it.
     */
    public function testTheFormMetadataIsLeftUntouched(): void
    {
        $meta = ['product-details' => ['children' => []]];

        $this->assertSame($meta, $this->modifier()->modifyMeta($meta));
    }

    private function modifier(bool $enabled = true): FeaturedColorField
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturnCallback(fn (): int => $this->currentStoreId);

        $locator = $this->createMock(LocatorInterface::class);
        $locator->method('getProduct')->willReturnCallback(fn (): FormProduct => $this->product);
        $locator->method('getStore')->willReturn($store);

        $resource = $this->createMock(FeaturedColorResource::class);
        $resource->method('loadByProduct')->willReturnCallback(
            function (int $productId, int $storeId = 0): ?array {
                $this->lookups[] = ['productId' => $productId, 'storeId' => $storeId];

                return $this->rows[$productId . ':' . $storeId] ?? null;
            }
        );

        $config = new Config(
            new ArrayScopeConfig(['test_featuredcolors/general/enabled' => $enabled ? '1' : '0']),
            'test_featuredcolors'
        );

        return new FeaturedColorField($locator, $resource, $config);
    }
}
