<?php

declare(strict_types=1);

namespace App\Command;

use App\Satis\BuildQueue;
use App\Satis\BuildRunner;
use App\Satis\BuildRunningException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:build:worker', description: 'Processes queued satis builds one after another (needs REDIS_URL)')]
final class BuildWorkerCommand extends Command
{
    private const POLL_SECONDS = 5;

    public function __construct(
        private readonly BuildQueue $queue,
        private readonly BuildRunner $builds,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Stop after this many jobs (0 = run forever)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->queue->isEnabled()) {
            $output->writeln('<error>REDIS_URL is not configured, the build queue is off.</error>');

            return Command::FAILURE;
        }
        $maxJobs = max(0, (int) $input->getOption('max-jobs'));
        $jobs = 0;

        $output->writeln(sprintf('Build worker started (pid %d).', getmypid()));
        while (true) {
            try {
                $recovered = $this->queue->recover();
                if ($recovered > 0) {
                    $output->writeln(sprintf('Moved %d unfinished job(s) back to the queue.', $recovered));
                }
                break;
            } catch (\Throwable $e) {
                $output->writeln('<comment>Redis not reachable: '.$e->getMessage().'. Retrying.</comment>');
                sleep(self::POLL_SECONDS);
            }
        }

        while (true) {
            try {
                $this->queue->heartbeat('idle');
                $job = $this->queue->reserve(self::POLL_SECONDS);
            } catch (\Throwable $e) {
                $output->writeln('<comment>Redis error: '.$e->getMessage().'. Retrying.</comment>');
                $this->logger->warning('Build worker: Redis error.', ['exception' => $e]);
                sleep(self::POLL_SECONDS);
                continue;
            }
            if (null === $job) {
                continue;
            }

            $label = [] === $job['repositories'] ? 'full build' : implode(', ', $job['repositories']);
            $output->writeln(sprintf('[%s] Starting %s (queued %s by %s).', date('H:i:s'), $label, $job['queued_at'], $job['trigger']));

            // A build started from the UI or CLI without the queue may be running: wait for it.
            $attempts = 0;
            while (true) {
                try {
                    $this->queue->heartbeat('building');
                    $exitCode = $this->builds->run($job['repositories'], $job['trigger'], static fn (string $text) => $output->write($text, false, OutputInterface::VERBOSITY_VERBOSE));
                    $output->writeln(sprintf('[%s] Finished %s with exit code %d.', date('H:i:s'), $label, $exitCode));
                    break;
                } catch (BuildRunningException) {
                    if (0 === $attempts++ % 15) {
                        $output->writeln('Another build is running, waiting.');
                    }
                    sleep(2);
                } catch (\Throwable $e) {
                    $output->writeln('<error>Build crashed: '.$e->getMessage().'</error>');
                    $this->logger->error('Build worker: build crashed.', ['exception' => $e]);
                    break;
                }
            }

            try {
                $this->queue->ack($job['raw']);
            } catch (\Throwable $e) {
                $this->logger->warning('Build worker: cannot acknowledge job.', ['exception' => $e]);
            }

            if ($maxJobs > 0 && ++$jobs >= $maxJobs) {
                return Command::SUCCESS;
            }
        }
    }
}
