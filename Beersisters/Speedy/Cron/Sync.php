<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Cron;

use Beersisters\Speedy\Model\Sync as SyncModel;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily refresh of Speedy's sites and offices. A failure leaves yesterday's data in place; it is recorded in
 * beersisters_speedy_sync, which the health gauge watches.
 */
class Sync
{
    /**
     * @var SyncModel
     */
    private $sync;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(SyncModel $sync, ScopeConfigInterface $scopeConfig, LoggerInterface $logger)
    {
        $this->sync = $sync;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->scopeConfig->isSetFlag('carriers/bsspeedy/sync_enabled')) {
            return;
        }
        try {
            $stats = $this->sync->run();
            $this->logger->info('Beersisters_Speedy: sync ok', $stats);
        } catch (\Throwable $e) {
            $this->logger->error('Beersisters_Speedy: sync failed: ' . $e->getMessage());
        }
    }
}
