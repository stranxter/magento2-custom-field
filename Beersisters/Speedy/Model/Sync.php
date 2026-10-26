<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Model;

use Beersisters\Speedy\Model\Api\ApiException;
use Beersisters\Speedy\Model\Api\Client;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Refreshes the local copy of Speedy's sites and offices (SPEEDY-PLAN.md §3.3).
 *
 * Download everything first, sanity-check it, then replace both tables in ONE transaction. A failed or
 * partial download never touches the live tables, and InnoDB keeps serving the old rows to the checkout
 * until the commit.
 */
class Sync
{
    public const COUNTRY_BG = 100;

    /** Sanity floors: the 2026-10-26 probe saw 5,323 sites and 1,361 offices. */
    private const MIN_SITES = 4000;
    private const MIN_OFFICES = 500;
    /** Refuse a refresh that shrinks a table by more than this vs. the last successful run. */
    private const MAX_SHRINK = 0.10;
    /** Rows with unsafe text are dropped; this many in one run means the feed itself is suspect: refuse it. */
    private const MAX_UNSAFE_ROWS = 20;

    private const TABLE_SITE = 'beersisters_speedy_site';
    private const TABLE_OFFICE = 'beersisters_speedy_office';
    private const TABLE_SYNC = 'beersisters_speedy_sync';

    private const SITE_COLUMNS = ['id', 'mainSiteId', 'type', 'name', 'nameEn', 'municipality', 'region', 'postCode',
        'addressNomenclature', 'x', 'y'];

    /**
     * @var Client
     */
    private $client;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var int rows dropped in this run because a text field was unsafe
     */
    private $unsafeRows = 0;

    public function __construct(Client $client, ResourceConnection $resource, DateTime $dateTime)
    {
        $this->client = $client;
        $this->resource = $resource;
        $this->dateTime = $dateTime;
    }

    /**
     * @return array{sites: int, offices: int, apt: int, skipped_offices: int}
     * @throws \Exception on any failure; the live tables are then unchanged and the run is logged as "error"
     */
    public function run(bool $dryRun = false): array
    {
        $connection = $this->resource->getConnection();
        $syncTable = $this->resource->getTableName(self::TABLE_SYNC);
        $connection->insert($syncTable, ['status' => 'running']);
        $syncId = (int)$connection->lastInsertId($syncTable);
        $this->unsafeRows = 0;

        try {
            $sites = $this->fetchSites();
            [$offices, $skipped] = $this->fetchOffices($sites);
            if ($this->unsafeRows > self::MAX_UNSAFE_ROWS) {
                throw new \RuntimeException(sprintf(
                    'Refusing to sync: %d rows with unsafe text (braces, markup, control characters)',
                    $this->unsafeRows
                ));
            }
            $this->checkCounts(count($sites), count($offices));

            if (!$dryRun) {
                $this->replace($sites, $offices);
            }
            $stats = [
                'sites' => count($sites),
                'offices' => count($offices),
                'apt' => count(array_filter($offices, static fn (array $o): bool => $o['type'] === 'APT')),
                'skipped_offices' => $skipped,
            ];
            $connection->update($syncTable, [
                'status' => $dryRun ? 'dry-run' : 'success',
                'finished_at' => $this->dateTime->gmtDate(),
                'site_count' => $stats['sites'],
                'office_count' => $stats['offices'],
                'message' => sprintf('APT %d, offices skipped %d, unsafe rows dropped %d', $stats['apt'], $skipped,
                    $this->unsafeRows),
            ], ['sync_id = ?' => $syncId]);

            return $stats;
        } catch (\Throwable $e) {
            $connection->update($syncTable, [
                'status' => 'error',
                'finished_at' => $this->dateTime->gmtDate(),
                'message' => mb_substr($e->getMessage(), 0, 1000),
            ], ['sync_id = ?' => $syncId]);
            throw $e;
        }
    }

    /**
     * @return array<int, array> rows keyed by site_id
     * @throws ApiException
     */
    private function fetchSites(): array
    {
        $csv = $this->client->requestCsv('/location/site/csv/' . self::COUNTRY_BG);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '');
        if (!is_array($header)) {
            throw new ApiException('Speedy sites CSV: empty');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        $index = array_flip($header);
        $missing = array_diff(self::SITE_COLUMNS, $header);
        if ($missing) {
            throw new ApiException('Speedy sites CSV: missing columns ' . implode(', ', $missing));
        }

        $sites = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $get = static fn (string $column): string => trim((string)($row[$index[$column]] ?? ''));
            $id = (int)$get('id');
            if ($id <= 0 || $get('name') === '') {
                continue;
            }
            $mainSiteId = (int)$get('mainSiteId');
            $site = [
                'site_id' => $id,
                'main_site_id' => $mainSiteId > 0 ? $mainSiteId : null,
                'type' => mb_substr($get('type'), 0, 20),
                'name' => mb_substr($get('name'), 0, 80),
                'name_en' => mb_substr($get('nameEn'), 0, 80),
                'municipality' => mb_substr($get('municipality'), 0, 80),
                'region' => mb_substr($get('region'), 0, 80),
                'post_code' => mb_substr($get('postCode'), 0, 10),
                'address_nomenclature' => min(2, max(0, (int)$get('addressNomenclature'))),
                'x' => $this->coordinate($get('x')),
                'y' => $this->coordinate($get('y')),
            ];
            if ($this->isSafe($site, ['type', 'name', 'name_en', 'municipality', 'region', 'post_code'])) {
                $sites[$id] = $site;
            }
        }
        fclose($handle);

        return $sites;
    }

    /**
     * Offices and automats valid today, in a known site.
     *
     * @return array{0: array<int, array>, 1: int} [rows keyed by office_id, number skipped]
     * @throws ApiException
     */
    private function fetchOffices(array $sites): array
    {
        $data = $this->client->requestJson('/location/office', ['countryId' => self::COUNTRY_BG]);
        $today = $this->dateTime->date('Y-m-d');
        $offices = [];
        $skipped = 0;
        foreach ($data['offices'] ?? [] as $office) {
            $id = (int)($office['id'] ?? 0);
            $siteId = (int)($office['siteId'] ?? 0);
            $type = (string)($office['type'] ?? '');
            $validFrom = (string)($office['validFrom'] ?? '');
            $validTo = (string)($office['validTo'] ?? '');
            if ($id <= 0 || !isset($sites[$siteId]) || !in_array($type, ['OFFICE', 'APT'], true)
                || ($validFrom !== '' && $validFrom > $today) || ($validTo !== '' && $validTo < $today)
                || (isset($office['pickUpAllowed']) && $office['pickUpAllowed'] === false)
            ) {
                $skipped++;
                continue;
            }
            $address = is_array($office['address'] ?? null) ? $office['address'] : [];
            $row = [
                'office_id' => $id,
                'site_id' => $siteId,
                'type' => $type,
                'name' => mb_substr(trim((string)($office['name'] ?? '')), 0, 100),
                'name_en' => mb_substr(trim((string)($office['nameEn'] ?? '')), 0, 100),
                'address' => mb_substr(trim((string)($address['fullAddressString'] ?? '')), 0, 255),
                'local_address' => mb_substr(trim((string)($address['localAddressString'] ?? '')), 0, 255),
                'post_code' => mb_substr(trim((string)($address['postCode'] ?? '')), 0, 10),
                'hours_full' => $this->hours($office, 'workingTimeFrom', 'workingTimeTo'),
                'hours_half' => $this->hours($office, 'workingTimeHalfFrom', 'workingTimeHalfTo'),
                'hours_dayoff' => $this->hours($office, 'workingTimeDayOffFrom', 'workingTimeDayOffTo'),
                'max_parcel_weight' => is_numeric($office['maxParcelWeight'] ?? null)
                    ? (float)$office['maxParcelWeight'] : null,
                'cash_payment' => !empty($office['cashPaymentAllowed']) ? 1 : 0,
                'card_payment' => !empty($office['cardPaymentAllowed']) ? 1 : 0,
                'x' => $this->coordinate((string)($address['x'] ?? '')),
                'y' => $this->coordinate((string)($address['y'] ?? '')),
            ];
            if ($this->isSafe($row, ['name', 'name_en', 'address', 'local_address', 'post_code'])) {
                $offices[$id] = $row;
            }
        }

        return [$offices, $skipped];
    }

    /**
     * @throws \RuntimeException
     */
    private function checkCounts(int $sites, int $offices): void
    {
        if ($sites < self::MIN_SITES || $offices < self::MIN_OFFICES) {
            throw new \RuntimeException(sprintf(
                'Refusing to sync: %d sites (min %d), %d offices (min %d)',
                $sites,
                self::MIN_SITES,
                $offices,
                self::MIN_OFFICES
            ));
        }

        $connection = $this->resource->getConnection();
        $last = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE_SYNC), ['site_count', 'office_count'])
                ->where('status = ?', 'success')
                ->order('sync_id DESC')
                ->limit(1)
        );
        if (!$last) {
            return;
        }
        foreach (['site_count' => $sites, 'office_count' => $offices] as $column => $now) {
            $before = (int)$last[$column];
            if ($before > 0 && $now < $before * (1 - self::MAX_SHRINK)) {
                throw new \RuntimeException(sprintf(
                    'Refusing to sync: %s dropped from %d to %d (more than %d%%)',
                    $column,
                    $before,
                    $now,
                    (int)(self::MAX_SHRINK * 100)
                ));
            }
        }
    }

    private function replace(array $sites, array $offices): void
    {
        $connection = $this->resource->getConnection();
        $siteTable = $this->resource->getTableName(self::TABLE_SITE);
        $officeTable = $this->resource->getTableName(self::TABLE_OFFICE);

        $connection->beginTransaction();
        try {
            $connection->delete($officeTable);
            $connection->delete($siteTable);
            foreach (array_chunk(array_values($sites), 500) as $chunk) {
                $connection->insertMultiple($siteTable, $chunk);
            }
            foreach (array_chunk(array_values($offices), 500) as $chunk) {
                $connection->insertMultiple($officeTable, $chunk);
            }
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Speedy's text ends up in order addresses, e-mails and the admin: treat it as untrusted. No template braces,
     * no markup, no control characters, valid UTF-8 only.
     */
    private function isSafe(array $row, array $fields): bool
    {
        foreach ($fields as $field) {
            $value = (string)($row[$field] ?? '');
            if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[{}<>\x00-\x1F\x7F]/u', $value) !== 0) {
                $this->unsafeRows++;

                return false;
            }
        }

        return true;
    }

    private function hours(array $office, string $from, string $to): string
    {
        $pattern = '/^\d{2}:\d{2}$/';
        $start = (string)($office[$from] ?? '');
        $end = (string)($office[$to] ?? '');

        return preg_match($pattern, $start) && preg_match($pattern, $end) ? $start . '-' . $end : '';
    }

    private function coordinate(string $value): ?float
    {
        return is_numeric($value) ? round((float)$value, 6) : null;
    }
}
