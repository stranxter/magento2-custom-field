<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Model;

use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;

/**
 * Speedy's region names (the 28 области) → Magento's BG regions (ISO 3166-2:BG codes).
 */
class RegionMap
{
    /**
     * Speedy region name → ISO code. The two Sofias: "София (столица)" is the city region, "София" the province.
     */
    public const CODES = [
        'Благоевград' => 'BG-01', 'Бургас' => 'BG-02', 'Варна' => 'BG-03', 'Велико Търново' => 'BG-04',
        'Видин' => 'BG-05', 'Враца' => 'BG-06', 'Габрово' => 'BG-07', 'Добрич' => 'BG-08',
        'Кърджали' => 'BG-09', 'Кюстендил' => 'BG-10', 'Ловеч' => 'BG-11', 'Монтана' => 'BG-12',
        'Пазарджик' => 'BG-13', 'Перник' => 'BG-14', 'Плевен' => 'BG-15', 'Пловдив' => 'BG-16',
        'Разград' => 'BG-17', 'Русе' => 'BG-18', 'Силистра' => 'BG-19', 'Сливен' => 'BG-20',
        'Смолян' => 'BG-21', 'София (столица)' => 'BG-22', 'София' => 'BG-23', 'Стара Загора' => 'BG-24',
        'Търговище' => 'BG-25', 'Хасково' => 'BG-26', 'Шумен' => 'BG-27', 'Ямбол' => 'BG-28',
    ];

    /**
     * Bulgarian display names (directory_country_region_name, locale bg_BG).
     */
    public const BG_NAMES = [
        'BG-01' => 'Благоевград', 'BG-02' => 'Бургас', 'BG-03' => 'Варна', 'BG-04' => 'Велико Търново',
        'BG-05' => 'Видин', 'BG-06' => 'Враца', 'BG-07' => 'Габрово', 'BG-08' => 'Добрич',
        'BG-09' => 'Кърджали', 'BG-10' => 'Кюстендил', 'BG-11' => 'Ловеч', 'BG-12' => 'Монтана',
        'BG-13' => 'Пазарджик', 'BG-14' => 'Перник', 'BG-15' => 'Плевен', 'BG-16' => 'Пловдив',
        'BG-17' => 'Разград', 'BG-18' => 'Русе', 'BG-19' => 'Силистра', 'BG-20' => 'Сливен',
        'BG-21' => 'Смолян', 'BG-22' => 'София (столица)', 'BG-23' => 'София (област)', 'BG-24' => 'Стара Загора',
        'BG-25' => 'Търговище', 'BG-26' => 'Хасково', 'BG-27' => 'Шумен', 'BG-28' => 'Ямбол',
    ];

    /**
     * @var CollectionFactory
     */
    private $regionCollectionFactory;

    /**
     * @var array<string, int>|null ISO code → region_id
     */
    private $ids;

    public function __construct(CollectionFactory $regionCollectionFactory)
    {
        $this->regionCollectionFactory = $regionCollectionFactory;
    }

    public function getRegionId(string $speedyRegion): ?int
    {
        $code = self::CODES[$speedyRegion] ?? null;
        if ($code === null) {
            return null;
        }
        if ($this->ids === null) {
            $this->ids = [];
            foreach ($this->regionCollectionFactory->create()->addCountryFilter('BG') as $region) {
                $this->ids[(string)$region->getCode()] = (int)$region->getId();
            }
        }

        return $this->ids[$code] ?? null;
    }

    public function getDisplayName(string $speedyRegion): string
    {
        return self::BG_NAMES[self::CODES[$speedyRegion] ?? ''] ?? $speedyRegion;
    }
}
