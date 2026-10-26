<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Read-only queries on the local Speedy tables, for the checkout lookups and for order validation.
 */
class Lookup
{
    public const MIN_TERM = 2;
    public const MAX_TERM = 50;
    public const MAX_SITES = 20;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var RegionMap
     */
    private $regionMap;

    public function __construct(ResourceConnection $resource, RegionMap $regionMap)
    {
        $this->resource = $resource;
        $this->regionMap = $regionMap;
    }

    /**
     * Sites whose BG or Latin name starts with $term. Towns first, then exact matches, then by name.
     * Municipality and region are returned because ~500 village names repeat.
     *
     * @return array<int, array{id: int, type: string, name: string, municipality: string, region: string,
     *     post_code: string, region_id: ?int}>
     */
    public function findSites(string $term, bool $withOfficesOnly = false): array
    {
        $term = trim($term);
        if (!mb_check_encoding($term, 'UTF-8')) {
            return [];
        }
        $length = mb_strlen($term);
        if ($length < self::MIN_TERM || $length > self::MAX_TERM) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $like = addcslashes($term, '\\%_') . '%';
        $select = $connection->select()
            ->from(
                ['s' => $this->resource->getTableName('beersisters_speedy_site')],
                ['id' => 'site_id', 'type', 'name', 'municipality', 'region', 'post_code']
            )
            ->where('s.name LIKE ? OR s.name_en LIKE ?', $like)
            ->order(new Expression("s.type = 'гр.' DESC"))
            ->order(new Expression('s.name = ' . $connection->quote($term) . ' DESC'))
            ->order('s.name')
            ->order('s.region')
            ->limit(self::MAX_SITES);
        if ($withOfficesOnly) {
            $officeTable = $this->resource->getTableName('beersisters_speedy_office');
            $select->where("EXISTS (SELECT 1 FROM {$officeTable} AS o WHERE o.site_id = s.site_id)");
        }

        return array_map(function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['region_id'] = $this->regionMap->getRegionId((string)$row['region']);
            $row['region'] = $this->regionMap->getDisplayName((string)$row['region']);

            return $row;
        }, $connection->fetchAll($select));
    }

    /**
     * The site for a typed city name, e.g. from a saved customer address: exact name (case-insensitive) and
     * postcode first, then a name that is unique on its own. Null when ambiguous or unknown.
     */
    public function resolveSite(string $city, string $postcode): ?array
    {
        $city = trim(preg_replace('/^(гр\.|с\.|град|село)\s*/u', '', trim($city)));
        if ($city === '' || mb_strlen($city) > self::MAX_TERM) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('beersisters_speedy_site'))
                ->where('name = ? OR name_en = ?', $city)
                ->limit(50)
        );
        foreach ($rows as $row) {
            if ($postcode !== '' && $row['post_code'] === trim($postcode)) {
                return $row;
            }
        }

        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * Offices and automats of one site: staffed offices first. Sofia has ~350 (2026-10-26), so the checkout
     * filters this list client-side.
     *
     * @return array<int, array>
     */
    public function findOffices(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('beersisters_speedy_office'),
                ['id' => 'office_id', 'type', 'name', 'address', 'local_address', 'post_code', 'hours_full',
                    'hours_half', 'hours_dayoff', 'cash_payment', 'card_payment']
            )
            ->where('site_id = ?', $siteId)
            ->order(new Expression("type = 'OFFICE' DESC"))
            ->order('name')
            ->limit(600);

        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['cash_payment'] = (bool)$row['cash_payment'];
            $row['card_payment'] = (bool)$row['card_payment'];

            return $row;
        }, $connection->fetchAll($select));
    }

    public function getSite(int $siteId): ?array
    {
        return $this->getRow('beersisters_speedy_site', 'site_id', $siteId);
    }

    public function getRegionMap(): RegionMap
    {
        return $this->regionMap;
    }

    public function getOffice(int $officeId): ?array
    {
        return $this->getRow('beersisters_speedy_office', 'office_id', $officeId);
    }

    private function getRow(string $table, string $key, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName($table))->where("{$key} = ?", $id)
        );

        return $row ?: null;
    }
}
