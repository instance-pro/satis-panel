<?php

declare(strict_types=1);

namespace App\Command;

use App\Auth\HtpasswdManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:user:set', description: 'Adds or updates a Composer client user (basic auth for the package files)')]
final class UserSetCommand extends Command
{
    public function __construct(private readonly HtpasswdManager $htpasswd)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = (string) $input->getArgument('username');
        try {
            $this->htpasswd->set($username, (string) $input->getArgument('password'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('User "%s" saved to %s.', $username, $this->htpasswd->path()));

        return Command::SUCCESS;
    }
}
