<?php
/**
 * SyncBaseImageTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Observer;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Observer\SyncBaseImage;
use Commerce\FeaturedColors\Test\Unit\Fake\RecordingLogger;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SyncBaseImageTest extends TestCase
{
    /** @var array<int, array{ids: int[], attributes: array<string, string>, storeId: int}> */
    private array $updates = [];

    private RecordingLogger $logger;
    private ProductAction&MockObject $productAction;

    protected function setUp(): void
    {
        $this->updates = [];
        $this->logger = new RecordingLogger();

        $this->productAction = $this->createMock(ProductAction::class);
        $this->productAction->method('updateAttributes')->willReturnCallback(
            function (array $ids, array $attributes, $storeId) {
                $this->updates[] = ['ids' => $ids, 'attributes' => $attributes, 'storeId' => (int) $storeId];

                return $this->productAction;
            }
        );
    }

    public function testTheBaseImageIsRepointedAtTheFeaturedColoursImage(): void
    {
        $this->observer()->execute($this->event([$this->row(10, '/c/e/ceil.jpg')]));

        self::assertCount(1, $this->updates);
        self::assertSame([10], $this->updates[0]['ids']);
        self::assertSame(
            ['image' => '/c/e/ceil.jpg', 'small_image' => '/c/e/ceil.jpg', 'thumbnail' => '/c/e/ceil.jpg'],
            $this->updates[0]['attributes']
        );
    }

    /**
     * The `image` attribute is a media-gallery relative path.
     */
    public function testWhatIsWrittenIsTheStoredMediaPathUnchanged(): void
    {
        $this->observer()->execute($this->event([$this->row(10, '/c/e/ceil.jpg')]));

        foreach ($this->updates[0]['attributes'] as $value) {
            self::assertStringStartsWith('/', $value);
            self::assertStringNotContainsString('http', $value);
        }
    }

    /**
     * `updateAttributes()` writes the batch in one statement per store and
     * loads no products at all.
     */
    public function testProductsSharingAnImageAreUpdatedInOneStatement(): void
    {
        $this->observer()->execute($this->event([
            $this->row(10, '/c/e/ceil.jpg'),
            $this->row(11, '/c/e/ceil.jpg'),
            $this->row(12, '/n/a/navy.jpg'),
        ]));

        self::assertCount(2, $this->updates);
        self::assertSame([10, 11], $this->updates[0]['ids']);
        self::assertSame([12], $this->updates[1]['ids']);
    }

    /**
     * The `image` attribute is store-scoped, so each store is written in its
     * own statement.
     */
    public function testEachStoreScopeIsWrittenSeparately(): void
    {
        $this->observer()->execute($this->event([
            $this->row(10, '/c/e/ceil.jpg', 1),
            $this->row(11, '/c/e/ceil.jpg', 2),
        ]));

        self::assertSame([1, 2], array_column($this->updates, 'storeId'));
    }

    /**
     * Repointing the base image rewrites a catalogue attribute.
     */
    public function testNothingHappensUnlessTheEventAsksForIt(): void
    {
        $this->observer()->execute(new Observer([
            'event' => new Event(['rows' => [$this->row(10, '/c/e/ceil.jpg')], 'sync_base_image' => false]),
        ]));

        self::assertSame([], $this->updates);
    }

    public function testAnEmptyOrMalformedRowSetIsIgnored(): void
    {
        $this->observer()->execute($this->event([]));
        $this->observer()->execute($this->event('not-an-array'));

        self::assertSame([], $this->updates);
    }

    /**
     * A migrated legacy row has no image path yet - the applier recomputes it
     * on the next run.
     */
    public function testARowWithNoImagePathIsSkipped(): void
    {
        $this->observer()->execute($this->event([
            $this->row(10, null),
            $this->row(11, ''),
            $this->row(12, '/n/a/navy.jpg'),
        ]));

        self::assertCount(1, $this->updates);
        self::assertSame([12], $this->updates[0]['ids']);
    }

    public function testARowWithNoProductIdIsSkipped(): void
    {
        $this->observer()->execute($this->event([
            [FeaturedColorInterface::IMAGE_PATH => '/c/e/ceil.jpg'],
            $this->row(12, '/n/a/navy.jpg'),
        ]));

        self::assertCount(1, $this->updates);
        self::assertSame([12], $this->updates[0]['ids']);
    }

    /**
     * The event fires after the featured colours are already committed.
     */
    public function testAFailedUpdateIsLoggedAndTheRestStillRun(): void
    {
        $calls = 0;
        $this->productAction = $this->createMock(ProductAction::class);
        $this->productAction->method('updateAttributes')->willReturnCallback(
            function (array $ids, array $attributes, $storeId) use (&$calls) {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('attribute is locked');
                }

                $this->updates[] = ['ids' => $ids, 'attributes' => $attributes, 'storeId' => (int) $storeId];

                return $this->productAction;
            }
        );

        $this->observer()->execute($this->event([
            $this->row(10, '/c/e/ceil.jpg'),
            $this->row(11, '/n/a/navy.jpg'),
        ]));

        self::assertCount(1, $this->updates);
        self::assertCount(1, $this->logger->errors);
        self::assertStringContainsString('base images', $this->logger->errors[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $productId, ?string $imagePath, int $storeId = 0): array
    {
        return [
            FeaturedColorInterface::PRODUCT_ID => $productId,
            FeaturedColorInterface::STORE_ID => $storeId,
            FeaturedColorInterface::IMAGE_PATH => $imagePath,
        ];
    }

    private function event(mixed $rows): Observer
    {
        return new Observer(['event' => new Event(['rows' => $rows, 'sync_base_image' => true])]);
    }

    private function observer(): SyncBaseImage
    {
        return new SyncBaseImage($this->productAction, $this->logger);
    }
}
