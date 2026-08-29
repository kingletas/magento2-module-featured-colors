<?php
/**
 * @package   Commerce_FeaturedColors
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\FeaturedColors\Test\Unit\Model;

use Commerce\FeaturedColors\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * The section id is a di.xml argument, which is what makes `bin/rebrand` a
     * mechanical rewrite rather than an edit of every config path.
     */
    public function testEveryPathIsReadUnderTheConfiguredSection(): void
    {
        $config = new Config(
            $this->scopeConfig([
                'acme_featuredcolors/general/enabled' => '1',
                'acme_featuredcolors/general/sync_base_image' => '1',
                'acme_featuredcolors/general/color_attribute' => 'colour_family',
            ]),
            'acme_featuredcolors'
        );

        $this->assertTrue($config->isEnabled());
        $this->assertTrue($config->shouldSyncBaseImage());
        $this->assertSame('colour_family', $config->getColorAttributeCode());
    }

    public function testAnUnconfiguredStoreHasTheFeatureOff(): void
    {
        $this->assertFalse($this->config([])->isEnabled());
    }

    public function testTheDisabledFlagIsReadAsAFlagRatherThanForTruthiness(): void
    {
        $this->assertFalse($this->config(['general/enabled' => '0'])->isEnabled());
    }

    /**
     * Repointing the base image rewrites a catalogue attribute on every applied
     * row.
     */
    public function testBaseImageSyncIsOffUnlessItIsSwitchedOn(): void
    {
        $this->assertFalse($this->config([])->shouldSyncBaseImage());
        $this->assertFalse($this->config(['general/sync_base_image' => '0'])->shouldSyncBaseImage());
        $this->assertTrue($this->config(['general/sync_base_image' => '1'])->shouldSyncBaseImage());
    }

    /**
     * `color` is Magento's own sample-data code, so the module works before
     * anything is configured.
     */
    public function testTheColourAttributeDefaultsToTheConventionalCode(): void
    {
        $this->assertSame('color', $this->config([])->getColorAttributeCode());
    }

    /**
     * An empty value in `core_config_data` must not become an empty attribute
     * code.
     */
    public function testAnEmptyColourAttributeFallsBackToTheDefault(): void
    {
        $this->assertSame('color', $this->config(['general/color_attribute' => ''])->getColorAttributeCode());
    }

    public function testTheStoreScopeIsPassedThrough(): void
    {
        $config = $this->config(['general/color_attribute' => 'colour_family']);

        $this->assertSame('colour_family', $config->getColorAttributeCode(2));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function config(array $values): Config
    {
        $qualified = [];

        foreach ($values as $path => $value) {
            $qualified['test_featuredcolors/' . $path] = $value;
        }

        return new Config($this->scopeConfig($qualified), 'test_featuredcolors');
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
