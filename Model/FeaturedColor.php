<?php
/**
 * FeaturedColor.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Model;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Magento requires the _construct() initialiser, which trips PHPMD naming.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 */
class FeaturedColor extends AbstractModel implements FeaturedColorInterface
{
    protected function _construct(): void
    {
        $this->_init(FeaturedColorResource::class);
    }

    public function getFeaturedColorId(): ?int
    {
        $value = $this->getData(self::FEATURED_COLOR_ID);

        return $value === null ? null : (int) $value;
    }

    public function setFeaturedColorId(?int $id): FeaturedColorInterface
    {
        return $this->setData(self::FEATURED_COLOR_ID, $id);
    }

    public function getProductId(): int
    {
        return (int) $this->getData(self::PRODUCT_ID);
    }

    public function setProductId(int $productId): FeaturedColorInterface
    {
        return $this->setData(self::PRODUCT_ID, $productId);
    }

    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): FeaturedColorInterface
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getChildProductId(): ?int
    {
        $value = $this->getData(self::CHILD_PRODUCT_ID);

        return $value === null ? null : (int) $value;
    }

    public function setChildProductId(?int $childProductId): FeaturedColorInterface
    {
        return $this->setData(self::CHILD_PRODUCT_ID, $childProductId);
    }

    public function getColorOptionId(): ?int
    {
        $value = $this->getData(self::COLOR_OPTION_ID);

        return $value === null ? null : (int) $value;
    }

    public function setColorOptionId(?int $colorOptionId): FeaturedColorInterface
    {
        return $this->setData(self::COLOR_OPTION_ID, $colorOptionId);
    }

    public function getColorLabel(): ?string
    {
        $value = $this->getData(self::COLOR_LABEL);

        return $value === null ? null : (string) $value;
    }

    public function setColorLabel(?string $colorLabel): FeaturedColorInterface
    {
        return $this->setData(self::COLOR_LABEL, $colorLabel);
    }

    public function getImagePath(): ?string
    {
        $value = $this->getData(self::IMAGE_PATH);

        return $value === null ? null : (string) $value;
    }

    public function setImagePath(?string $imagePath): FeaturedColorInterface
    {
        return $this->setData(self::IMAGE_PATH, $imagePath);
    }

    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);

        return $value === null ? null : (string) $value;
    }

    public function setUpdatedAt(?string $updatedAt): FeaturedColorInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
