<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Console\Command;

use Beersisters\Speedy\Model\Sync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento beersisters:speedy:sync [--dry-run]
 */
class SyncCommand extends Command
{
    /**
     * @var Sync
     */
    private $sync;

    public function __construct(Sync $sync)
    {
        $this->sync = $sync;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('beersisters:speedy:sync')
            ->setDescription('Download Speedy sites and offices into the local tables')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Download and check, but do not write');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        try {
            $stats = $this->sync->run($dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf(
            '%s: %d sites, %d offices (%d automats), %d offices skipped',
            $dryRun ? 'Dry run, nothing written' : 'Synced',
            $stats['sites'],
            $stats['offices'],
            $stats['apt'],
            $stats['skipped_offices']
        ));

        return Command::SUCCESS;
    }
}
