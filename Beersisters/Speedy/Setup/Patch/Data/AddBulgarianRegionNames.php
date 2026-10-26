<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Setup\Patch\Data;

use Beersisters\Speedy\Model\RegionMap;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Magento ships Bulgaria's 28 regions with English names only ("Ruse"); add the bg_BG names ("Русе").
 */
class AddBulgarianRegionNames implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $regions = $connection->fetchPairs(
            $connection->select()
                ->from($this->moduleDataSetup->getTable('directory_country_region'), ['code', 'region_id'])
                ->where('country_id = ?', 'BG')
        );
        $rows = [];
        foreach (RegionMap::BG_NAMES as $code => $name) {
            if (isset($regions[$code])) {
                $rows[] = ['locale' => 'bg_BG', 'region_id' => (int)$regions[$code], 'name' => $name];
            }
        }
        if ($rows) {
            $connection->insertOnDuplicate(
                $this->moduleDataSetup->getTable('directory_country_region_name'),
                $rows,
                ['name']
            );
        }

        return $this;
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
