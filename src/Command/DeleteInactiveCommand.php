<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use App\Entity\User;
use DateInterval;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @package App\Command
 */
#[AsCommand(name: 'app:delete-inactive-users', description: 'Delete registrations never confirmed, 48 hours after they were made.')]
class DeleteInactiveCommand extends Command
{
    protected EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = new DateTime();
        $limit->sub(new DateInterval('PT48H'));
        $count = 0;

        // only registrations never confirmed: not the accounts disabled because nobody logs in as
        // them (historic authors, organizations, merged accounts), nor one an admin disabled,
        // all of which deleting would take their decks with them (Ketura, 2026-11-09)
        $users = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.enabled = false')
            ->andWhere('u.confirmationToken IS NOT NULL')
            ->andWhere('u.lastLogin IS NULL')
            ->andWhere('u.historic = false')
            ->andWhere('u.organization = false')
            ->andWhere('u.mergedInto IS NULL')
            ->getQuery()->getResult();
        foreach ($users as $user) {
            /* @var UserInterface $user */
            if ($user->getDateCreation() < $limit) {
                $count++;
                $this->entityManager->remove($user);
            }
        }
        $this->entityManager->flush();
        $output->writeln(date('c') . " Delete $count inactive users.");
        return Command::SUCCESS;
    }
}
