<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Model\Catalog;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Model\Product as ProductEntity;

/**
 * Colour attribute options, loaded once and indexed for lookup.
 */
class ColorOptionMap
{
    /** @var array<string, int>|null Lowercased label => option id. */
    private ?array $idsByLabel = null;

    /** @var array<int, string>|null Option id => label as authored. */
    private ?array $labelsById = null;

    private ?int $attributeId = null;

    public function __construct(
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly string $attributeCode = 'color'
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getAttributeId(): int
    {
        return $this->attributeId ??= (int) $this->getAttribute()->getAttributeId();
    }

    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    /**
     * Resolve a colour label to its option id.
     *
     * @throws NoSuchEntityException
     */
    public function findOptionIdByLabel(string $label): ?int
    {
        return $this->getIdsByLabel()[$this->normalise($label)] ?? null;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function findLabelByOptionId(int $optionId): ?string
    {
        return $this->getLabelsById()[$optionId] ?? null;
    }

    /**
     * @return array<string, int>
     *
     * @throws NoSuchEntityException
     */
    private function getIdsByLabel(): array
    {
        $this->load();

        return $this->idsByLabel ?? [];
    }

    /**
     * @return array<int, string>
     *
     * @throws NoSuchEntityException
     */
    private function getLabelsById(): array
    {
        $this->load();

        return $this->labelsById ?? [];
    }

    /**
     * @throws NoSuchEntityException
     */
    private function load(): void
    {
        if ($this->idsByLabel !== null) {
            return;
        }

        $this->idsByLabel = [];
        $this->labelsById = [];

        $attribute = $this->getAttribute();

        // Only an EAV attribute has a source model to read labels from.
        if (!$attribute instanceof AbstractAttribute || !$attribute->usesSource()) {
            return;
        }

        // Admin-scope labels: store-scoped ones would make an import behave
        // differently depending on which store happened to be current.
        foreach ($attribute->getSource()->getAllOptions() as $option) {
            $label = (string) ($option['label'] ?? '');
            $value = $option['value'] ?? null;

            if ($label === '' || $value === null || $value === '') {
                continue;
            }

            $this->idsByLabel[$this->normalise($label)] = (int) $value;
            $this->labelsById[(int) $value] = $label;
        }
    }

    /**
     * @throws NoSuchEntityException
     */
    private function getAttribute(): AttributeInterface
    {
        return $this->attributeRepository->get(ProductEntity::ENTITY, $this->attributeCode);
    }

    private function normalise(string $label): string
    {
        return mb_strtolower(trim($label));
    }
}
