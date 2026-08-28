<?php
/**
 * CaptureFeaturedColorPluginTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Plugin\Catalog\Adminhtml;

use Commerce\FeaturedColors\Plugin\Catalog\Adminhtml\CaptureFeaturedColorPlugin;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper;
use Commerce\FeaturedColors\Test\Unit\Fake\FormProduct;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CaptureFeaturedColorPluginTest extends TestCase
{
    private mixed $posted = null;

    public function testThePostedColourIsMovedOntoTheProduct(): void
    {
        $this->posted = ['featuredcolor' => 'Ceil Blue'];
        $product = $this->product();

        $this->plugin()->afterInitialize($this->subject(), $product);

        $this->assertSame('Ceil Blue', $product->getData(CaptureFeaturedColorPlugin::DATA_KEY));
    }

    public function testTheProductIsReturnedForTheNextPlugin(): void
    {
        $product = $this->product();

        $this->assertSame($product, $this->plugin()->afterInitialize($this->subject(), $product));
    }

    /**
     * A padded label matches no attribute option, so the value is trimmed
     * first.
     */
    public function testThePostedValueIsTrimmed(): void
    {
        $this->posted = ['featuredcolor' => "  Ceil Blue \n"];
        $product = $this->product();

        $this->plugin()->afterInitialize($this->subject(), $product);

        $this->assertSame('Ceil Blue', $product->getData(CaptureFeaturedColorPlugin::DATA_KEY));
    }

    /**
     * An empty submission is the merchant clearing the field, not the field
     * being absent.
     */
    public function testAnEmptySubmissionSetsTheKeyToTheEmptyString(): void
    {
        $this->posted = ['featuredcolor' => ''];
        $product = $this->product();

        $this->plugin()->afterInitialize($this->subject(), $product);

        $this->assertTrue($product->hasData(CaptureFeaturedColorPlugin::DATA_KEY));
        $this->assertSame('', $product->getData(CaptureFeaturedColorPlugin::DATA_KEY));
    }

    /**
     * The absent key is the whole contract with the observer: no key means the
     * caller expressed no opinion, and the stored colour is left alone.
     */
    public function testAFormWithoutTheFieldLeavesTheProductUntouched(): void
    {
        $this->posted = null;
        $product = $this->product();

        $this->plugin()->afterInitialize($this->subject(), $product);

        $this->assertFalse($product->hasData(CaptureFeaturedColorPlugin::DATA_KEY));
    }

    /**
     * A scalar where the form posts a group must not become a type error inside
     * the product save.
     */
    public function testANonArrayParameterIsIgnored(): void
    {
        foreach (['Ceil Blue', 42, false] as $value) {
            $this->posted = $value;
            $product = $this->product();

            $this->plugin()->afterInitialize($this->subject(), $product);

            $this->assertFalse($product->hasData(CaptureFeaturedColorPlugin::DATA_KEY));
        }
    }

    /**
     * A group posted without its inner field is an explicit empty rather than
     * an absence.
     */
    public function testAGroupWithoutTheInnerFieldClearsTheValue(): void
    {
        $this->posted = ['something_else' => 'x'];
        $product = $this->product();

        $this->plugin()->afterInitialize($this->subject(), $product);

        $this->assertSame('', $product->getData(CaptureFeaturedColorPlugin::DATA_KEY));
    }

    /**
     * Reading the request belongs in the controller layer.
     */
    public function testTheRequestKeyAndTheDataKeyAreDeliberatelyDifferent(): void
    {
        $this->assertNotSame(
            CaptureFeaturedColorPlugin::REQUEST_KEY,
            CaptureFeaturedColorPlugin::DATA_KEY
        );
    }

    private function plugin(): CaptureFeaturedColorPlugin
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')
            ->with(CaptureFeaturedColorPlugin::REQUEST_KEY)
            ->willReturnCallback(fn () => $this->posted);

        return new CaptureFeaturedColorPlugin($request);
    }

    private function subject(): Helper&MockObject
    {
        return $this->createMock(Helper::class);
    }

    /**
     * The real product model, because `hasData()` telling absence from empty is
     * what is tested.
     */
    private function product(): ProductInterface
    {
        return new FormProduct();
    }
}
