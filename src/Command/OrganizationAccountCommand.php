<?php

namespace App\Command;

use App\Services\OrganizationAccounts;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An organization's account (Ketura, 2026-11-09): made, renamed, or an existing ordinary
 * account turned into one with --convert (its login stops working)
 */
#[AsCommand(name: 'app:account:organization', description: "Make an organization's account (nobody logs in as it), or turn an existing account into one.")]
class OrganizationAccountCommand extends Command
{
    public function __construct(
        private readonly OrganizationAccounts $organizations,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The username, which addresses it (APP_OFFICIAL_ACCOUNT names the PC\'s)')
            ->addArgument('display-name', InputArgument::OPTIONAL, 'The name shown on the site, e.g. "Player\'s Council"')
            ->addOption('convert', null, InputOption::VALUE_NONE, 'Turn an existing ordinary account into an organization\'s: its password stops working and its roles are dropped');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $displayName = $input->getArgument('display-name');
        $user = $this->organizations->find($username);
        try {
            if ($user === null) {
                $user = $this->organizations->make($username, (string) ($displayName ?? $username));
                $done = 'Made';
            } elseif ($user->isOrganization()) {
                if ($displayName !== null) {
                    $user->setDisplayName(mb_substr(trim((string) $displayName), 0, 100));
                }
                $done = 'Updated';
            } elseif ($input->getOption('convert')) {
                $this->organizations->convert($user, $displayName);
                $done = 'Converted';
            } else {
                $io->error(sprintf('"%s" is an ordinary account. Pass --convert to make it an organization\'s; nobody will be able to log in as it.', $username));
                return Command::FAILURE;
            }
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $this->em->flush();
        $io->success(sprintf('%s the organization account %s ("%s").', $done, $user->getUsername(), $user->getDisplayName()));
        return Command::SUCCESS;
    }
}
