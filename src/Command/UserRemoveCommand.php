<?php

declare(strict_types=1);

namespace App\Command;

use App\Auth\HtpasswdManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:user:remove', description: 'Removes a Composer client user')]
final class UserRemoveCommand extends Command
{
    public function __construct(private readonly HtpasswdManager $htpasswd)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = (string) $input->getArgument('username');
        if (!$this->htpasswd->has($username)) {
            $output->writeln(sprintf('<comment>User "%s" does not exist.</comment>', $username));

            return Command::SUCCESS;
        }
        $this->htpasswd->remove($username);
        $output->writeln(sprintf('User "%s" removed.', $username));

        return Command::SUCCESS;
    }
}
