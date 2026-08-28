<?php
/**
 * ColorOptionMapTest.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model\Catalog;

use Commerce\FeaturedColors\Model\Catalog\ColorOptionMap;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\SourceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ColorOptionMapTest extends TestCase
{
    private AttributeRepositoryInterface&MockObject $attributeRepository;
    private SourceInterface&MockObject $source;
    private ColorOptionMap $map;

    protected function setUp(): void
    {
        $this->source = $this->createMock(SourceInterface::class);
        $this->source->method('getAllOptions')->willReturn([
            ['label' => 'Ceil Blue', 'value' => '12'],
            ['label' => 'Wine', 'value' => '13'],
            ['label' => '', 'value' => '14'],
            ['label' => 'Nothing', 'value' => ''],
        ]);

        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn('93');
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($this->source);

        $this->attributeRepository = $this->createMock(AttributeRepositoryInterface::class);
        $this->attributeRepository->method('get')->willReturn($attribute);

        $this->map = new ColorOptionMap($this->attributeRepository, 'color');
    }

    public function testResolvesALabelToItsOptionId(): void
    {
        self::assertSame(12, $this->map->findOptionIdByLabel('Ceil Blue'));
    }

    /**
     * Labels come from merchant-authored CSVs, so matching must tolerate case
     * and stray whitespace.
     */
    public function testMatchingIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame(12, $this->map->findOptionIdByLabel('  ceil blue '));
        self::assertSame(13, $this->map->findOptionIdByLabel('WINE'));
    }

    public function testAnUnknownLabelResolvesToNullRatherThanRaising(): void
    {
        self::assertNull($this->map->findOptionIdByLabel('Chartreuse'));
    }

    public function testBlankLabelsAndValuesAreIgnored(): void
    {
        self::assertNull($this->map->findOptionIdByLabel(''));
        self::assertNull($this->map->findOptionIdByLabel('Nothing'));
    }

    public function testReverseLookupReturnsTheLabelAsAuthored(): void
    {
        self::assertSame('Ceil Blue', $this->map->findLabelByOptionId(12));
        self::assertNull($this->map->findLabelByOptionId(999));
    }

    /**
     * The whole point of this class.
     */
    public function testOptionsAreLoadedOnceHoweverManyLookupsHappen(): void
    {
        $this->source->expects(self::once())->method('getAllOptions');

        $this->map->findOptionIdByLabel('Wine');
        $this->map->findOptionIdByLabel('Ceil Blue');
        $this->map->findOptionIdByLabel('Wine');
        $this->map->findLabelByOptionId(13);
    }

    public function testExposesTheAttributeIdAndCode(): void
    {
        self::assertSame(93, $this->map->getAttributeId());
        self::assertSame('color', $this->map->getAttributeCode());
    }
}
