<?php

declare(strict_types=1);

namespace App\Command;

use App\Satis\BuildQueue;
use App\Satis\BuildRunner;
use App\Satis\BuildRunningException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:satis:build', description: 'Runs "satis build" for the configured satis.json (full or per repository)')]
final class SatisBuildCommand extends Command
{
    public function __construct(
        private readonly BuildRunner $builds,
        private readonly BuildQueue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('repository-url', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only rebuild the repository with this URL (repeatable)')
            ->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'Label shown in the build status', 'cli')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Put the build into the queue instead of running it now (needs REDIS_URL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $urls */
        $urls = array_values(array_filter((array) $input->getOption('repository-url')));
        if ($input->getOption('queue')) {
            if (!$this->queue->isEnabled()) {
                $output->writeln('<error>REDIS_URL is not configured, the build queue is off.</error>');

                return Command::FAILURE;
            }
            $result = $this->queue->enqueue($urls, (string) $input->getOption('trigger'));
            $output->writeln(sprintf('%s (%d job(s) waiting).', 'merged' === $result ? 'An equivalent build is already queued' : 'Build queued', $this->queue->length()));

            return Command::SUCCESS;
        }
        try {
            return $this->builds->run($urls, (string) $input->getOption('trigger'), static fn (string $text) => $output->write($text));
        } catch (BuildRunningException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
    }
}
