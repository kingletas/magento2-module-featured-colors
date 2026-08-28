<?php
/**
 * PersistFeaturedColorTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Observer;

use Commerce\FeaturedColors\Model\Assignment;
use Commerce\FeaturedColors\Model\AssignmentResult;
use Commerce\FeaturedColors\Model\Config;
use Commerce\FeaturedColors\Model\FeaturedColorApplier;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Commerce\FeaturedColors\Observer\PersistFeaturedColor;
use Commerce\FeaturedColors\Plugin\Catalog\Adminhtml\CaptureFeaturedColorPlugin;
use Commerce\FeaturedColors\Test\Support\FormProduct;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PersistFeaturedColorTest extends TestCase
{
    /** @var Assignment[] */
    private array $applied = [];

    /** @var array<int, array{ids: int[], storeId: int|null}> */
    private array $deletes = [];

    /** @var string[] */
    private array $warnings = [];

    private LoggerInterface&MockObject $logger;
    private FeaturedColorApplier&MockObject $applier;
    private FeaturedColorResource&MockObject $resource;
    private AssignmentResult $result;

    protected function setUp(): void
    {
        $this->applied = [];
        $this->deletes = [];
        $this->warnings = [];
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->result = new AssignmentResult(1);

        $this->applier = $this->createMock(FeaturedColorApplier::class);
        $this->applier->method('apply')->willReturnCallback(
            function (array $assignments): AssignmentResult {
                $this->applied = array_merge($this->applied, $assignments);

                return $this->result;
            }
        );

        $this->resource = $this->createMock(FeaturedColorResource::class);
        $this->resource->method('deleteByProducts')->willReturnCallback(
            function (array $ids, ?int $storeId = null): int {
                $this->deletes[] = ['ids' => $ids, 'storeId' => $storeId];

                return 1;
            }
        );
    }

    public function testTheChosenColourIsAppliedForTheProductsStore(): void
    {
        $this->observer()->execute($this->event($this->product('SKU-1', 'Ceil Blue', storeId: 2)));

        $this->assertCount(1, $this->applied);
        $this->assertSame('SKU-1', $this->applied[0]->sku);
        $this->assertSame('Ceil Blue', $this->applied[0]->colorLabel);
        $this->assertSame(2, $this->applied[0]->storeId);
    }

    /**
     * The data key rather than the request, so an import, API or CLI save
     * cannot steer it.
     */
    public function testTheColourIsReadFromTheProductRatherThanTheRequest(): void
    {
        $product = $this->product('SKU-1', 'Ceil Blue');

        $this->observer()->execute($this->event($product));

        $this->assertSame('Ceil Blue', $product->getData(CaptureFeaturedColorPlugin::DATA_KEY));
        $this->assertCount(1, $this->applied);
    }

    public function testTheLabelIsTrimmedBeforeItIsApplied(): void
    {
        $this->observer()->execute($this->event($this->product('SKU-1', "  Ceil Blue \n")));

        $this->assertSame('Ceil Blue', $this->applied[0]->colorLabel);
    }

    /**
     * An absent key means the caller expressed no opinion.
     */
    public function testAProductWithoutTheKeyIsLeftAlone(): void
    {
        $product = new FormProduct();
        $product->setData('sku', 'SKU-1');
        $product->setData('type_id', Configurable::TYPE_CODE);

        $this->observer()->execute($this->event($product));

        $this->assertSame([], $this->applied);
        $this->assertSame([], $this->deletes);
    }

    /**
     * An empty submission is the merchant clearing the field, which removes the
     * row rather than writing a blank colour.
     */
    public function testAnEmptySubmissionRemovesTheStoredColour(): void
    {
        $this->observer()->execute($this->event($this->product('SKU-1', '', productId: 10, storeId: 2)));

        $this->assertSame([['ids' => [10], 'storeId' => 2]], $this->deletes);
        $this->assertSame([], $this->applied);
    }

    /**
     * Only a configurable has colour variants to feature.
     */
    public function testASimpleProductIsIgnored(): void
    {
        $this->observer()->execute($this->event($this->product('SKU-1', 'Ceil Blue', typeId: 'simple')));

        $this->assertSame([], $this->applied);
    }

    public function testNothingHappensWhenTheFeatureIsDisabled(): void
    {
        $this->observer(enabled: false)->execute($this->event($this->product('SKU-1', 'Ceil Blue')));

        $this->assertSame([], $this->applied);
    }

    /**
     * The event carries whatever dispatched it.
     */
    public function testAnEventWithoutAProductIsIgnored(): void
    {
        $this->observer()->execute(new Observer(['event' => new Event([])]));
        $this->observer()->execute(new Observer(['event' => new Event(['product' => 'SKU-1'])]));

        $this->assertSame([], $this->applied);
    }

    /**
     * A refused save says why in the admin rather than returning false
     * silently.
     */
    public function testAnAssignmentFailureIsShownToTheMerchant(): void
    {
        $this->result = new AssignmentResult(0, 0, [0 => __('SKU "SKU-1" has no enabled child in "Ceil Blue".')]);

        $this->observer()->execute($this->event($this->product('SKU-1', 'Ceil Blue')));

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('no enabled child', $this->warnings[0]);
    }

    /**
     * The product itself saved successfully.
     */
    public function testAFailureNeverAbortsTheProductSave(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->applier = $this->createMock(FeaturedColorApplier::class);
        $this->applier->method('apply')->willThrowException(new RuntimeException('lock wait timeout'));

        $this->observer()->execute($this->event($this->product('SKU-1', 'Ceil Blue')));

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('was saved', $this->warnings[0]);
    }

    /**
     * Detail goes to the log; the merchant gets a sentence they can act on.
     */
    public function testTheMerchantIsNotShownTheInternalError(): void
    {
        $this->applier = $this->createMock(FeaturedColorApplier::class);
        $this->applier->method('apply')->willThrowException(new RuntimeException('lock wait timeout'));

        $this->observer()->execute($this->event($this->product('SKU-1', 'Ceil Blue')));

        $this->assertStringNotContainsString('lock wait', $this->warnings[0]);
    }

    private function product(
        string $sku,
        string $label,
        int $productId = 10,
        int $storeId = 0,
        string $typeId = Configurable::TYPE_CODE
    ): FormProduct {
        $product = new FormProduct();
        $product->setData('entity_id', $productId);
        $product->setData('sku', $sku);
        $product->setData('store_id', $storeId);
        $product->setData('type_id', $typeId);
        $product->setData(CaptureFeaturedColorPlugin::DATA_KEY, $label);

        return $product;
    }

    private function event(mixed $product): Observer
    {
        return new Observer(['event' => new Event(['product' => $product])]);
    }

    private function observer(bool $enabled = true): PersistFeaturedColor
    {
        $messageManager = $this->createMock(MessageManagerInterface::class);
        $messageManager->method('addWarningMessage')->willReturnCallback(
            function ($message) use (&$messageManager) {
                $this->warnings[] = (string) $message;

                return $messageManager;
            }
        );

        $config = new Config(
            $this->scopeConfig(['test_featuredcolors/general/enabled' => $enabled ? '1' : '0']),
            'test_featuredcolors'
        );

        return new PersistFeaturedColor(
            $this->applier,
            $this->resource,
            $messageManager,
            $config,
            $this->logger
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return $scopeConfig;
    }
}
