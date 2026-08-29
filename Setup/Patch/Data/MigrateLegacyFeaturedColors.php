<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Setup\Patch\Data;

use Commerce\FeaturedColors\Api\Data\FeaturedColorInterface;
use Commerce\FeaturedColors\Model\ResourceModel\FeaturedColor as FeaturedColorResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies featured colours out of the legacy JSON-blob table.
 */
class MigrateLegacyFeaturedColors implements DataPatchInterface
{
    private const int CHUNK_SIZE = 5000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly string $legacyTable = '',
        private readonly string $legacyColumn = 'default'
    ) {
    }

    public function apply(): static
    {
        if ($this->legacyTable === '') {
            return $this;
        }

        $connection = $this->resourceConnection->getConnection();
        $legacyTable = $this->resourceConnection->getTableName($this->legacyTable);

        if (!$connection->isTableExists($legacyTable)) {
            return $this;
        }

        $targetTable = $this->resourceConnection->getTableName(FeaturedColorResource::TABLE_NAME);
        $offset = 0;
        $migrated = 0;

        while (true) {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($legacyTable)
                    ->order('product_id ASC')
                    ->limit(self::CHUNK_SIZE, $offset)
            );

            if ($rows === []) {
                break;
            }

            $offset += self::CHUNK_SIZE;
            $batch = [];

            foreach ($rows as $row) {
                $decoded = $this->decode((string) ($row[$this->legacyColumn] ?? ''));

                if ($decoded === null) {
                    continue;
                }

                $batch[] = [
                    FeaturedColorInterface::PRODUCT_ID => (int) $row['product_id'],
                    FeaturedColorInterface::STORE_ID => (int) ($row['store_id'] ?? 0),
                    FeaturedColorInterface::CHILD_PRODUCT_ID => isset($decoded['child_id'])
                        ? (int) $decoded['child_id']
                        : null,
                    FeaturedColorInterface::COLOR_OPTION_ID => null,
                    FeaturedColorInterface::COLOR_LABEL => isset($decoded['label'])
                        ? (string) $decoded['label']
                        : null,
                    // The legacy full URL is not carried over; the next apply
                    // recomputes the media path.
                    FeaturedColorInterface::IMAGE_PATH => null,
                ];
            }

            if ($batch !== []) {
                $connection->insertOnDuplicate($targetTable, $batch, [
                    FeaturedColorInterface::CHILD_PRODUCT_ID,
                    FeaturedColorInterface::COLOR_LABEL,
                ]);
                $migrated += count($batch);
            }
        }

        $this->logger->info(sprintf('Featured colours: migrated %d legacy row(s).', $migrated));

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return null;
        }

        try {
            $decoded = $this->serializer->unserialize($json);
        } catch (Throwable) {
            // A single unparseable blob must not abort the migration.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
