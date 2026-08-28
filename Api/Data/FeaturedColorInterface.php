<?php
/**
 * FeaturedColorInterface.php
 *
 * @package     Commerce_FeaturedColors
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\FeaturedColors\Api\Data;

/**
 * The colour variant a configurable product leads with.
 */
interface FeaturedColorInterface
{
    public const string FEATURED_COLOR_ID = 'featured_color_id';
    public const string PRODUCT_ID = 'product_id';
    public const string STORE_ID = 'store_id';
    public const string CHILD_PRODUCT_ID = 'child_product_id';
    public const string COLOR_OPTION_ID = 'color_option_id';
    public const string COLOR_LABEL = 'color_label';
    public const string IMAGE_PATH = 'image_path';
    public const string UPDATED_AT = 'updated_at';

    public function getFeaturedColorId(): ?int;

    public function setFeaturedColorId(?int $id): self;

    public function getProductId(): int;

    public function setProductId(int $productId): self;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function getChildProductId(): ?int;

    public function setChildProductId(?int $childProductId): self;

    /**
     * The colour attribute's option id.
     */
    public function getColorOptionId(): ?int;

    public function setColorOptionId(?int $colorOptionId): self;

    public function getColorLabel(): ?string;

    public function setColorLabel(?string $colorLabel): self;

    public function getImagePath(): ?string;

    public function setImagePath(?string $imagePath): self;

    public function getUpdatedAt(): ?string;

    public function setUpdatedAt(?string $updatedAt): self;
}
