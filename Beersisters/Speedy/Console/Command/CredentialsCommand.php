<?php
declare(strict_types=1);

namespace Beersisters\Speedy\Console\Command;

use Beersisters\Speedy\Model\Api\Client;
use Beersisters\Speedy\Model\Sync;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * bin/magento beersisters:speedy:credentials
 *
 * Prompts for the Speedy API username and password (hidden), tests them with one read-only call, and only then
 * stores them (the password encrypted). The values never appear on a command line, so they are not visible in
 * `ps` or in the shell history (SPEEDY-PLAN.md §3.6).
 */
class CredentialsCommand extends Command
{
    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var ReinitableConfigInterface
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    public function __construct(
        WriterInterface $configWriter,
        EncryptorInterface $encryptor,
        ReinitableConfigInterface $config,
        Client $client
    ) {
        $this->configWriter = $configWriter;
        $this->encryptor = $encryptor;
        $this->config = $config;
        $this->client = $client;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('beersisters:speedy:credentials')
            ->setDescription('Test and set the Speedy API username and password (prompted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive()) {
            $output->writeln('<error>Needs an interactive terminal (or SHELL_INTERACTIVE=1 with input on stdin).</error>');

            return Command::FAILURE;
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $username = trim((string)$helper->ask($input, $output, new Question('Speedy API username: ')));
        $question = new Question('Speedy API password: ');
        $question->setHidden(true);
        // Never echo the password if the terminal can't hide input (e.g. docker exec without -t).
        $question->setHiddenFallback(false);
        $password = (string)$helper->ask($input, $output, $question);

        if ($username === '' || $password === '') {
            $output->writeln('<error>Username and password are both required; nothing saved.</error>');

            return Command::FAILURE;
        }

        // Test first, save only on success: a typo must not replace working credentials.
        try {
            $data = $this->client->requestJson('/location/country/' . Sync::COUNTRY_BG, [], [$username, $password]);
        } catch (\Exception $e) {
            $output->writeln('<error>Test failed, nothing saved: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $this->configWriter->save('carriers/bsspeedy/api_username', $username);
        $this->configWriter->save('carriers/bsspeedy/api_password', $this->encryptor->encrypt($password));
        $this->config->reinit();
        $output->writeln(sprintf(
            'OK: Speedy answered for country %s. Saved (password encrypted).',
            $data['country']['name'] ?? '?'
        ));

        return Command::SUCCESS;
    }
}
