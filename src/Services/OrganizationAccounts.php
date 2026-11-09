<?php

namespace App\Services;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use FOS\UserBundle\Model\UserManagerInterface;
use RuntimeException;

/**
 * Organization accounts (Ketura, 2026-11-09): Decipher's, the Players Council's. Like a
 * historic account, nobody logs in as one: it's disabled, no password matches it, and it can't
 * be claimed or merged. It only owns things (the official libraries), and admins edit its
 * profile. Made the first time it's needed, or by app:account:organization.
 */
class OrganizationAccounts
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserManagerInterface $userManager,
        private readonly string $officialAccount,
        private readonly string $officialAccountName,
    ) {
    }

    /** The PC's own account, which owns the official libraries (APP_OFFICIAL_ACCOUNT) */
    public function official(): User
    {
        return $this->get($this->officialAccount, $this->officialAccountName);
    }

    /**
     * The organization's account, made if there's none by that username yet
     * @throws RuntimeException when the username is a person's account
     */
    public function get(string $username, string $displayName): User
    {
        $user = $this->find($username);
        if ($user === null) {
            return $this->make($username, $displayName);
        }
        if (!$user->isOrganization()) {
            throw new RuntimeException(sprintf('"%s" is a person\'s account, not an organization\'s; make it one with app:account:organization --convert.', $username));
        }
        return $user;
    }

    public function find(string $username): ?User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['usernameCanonical' => mb_strtolower(trim($username))]);
        return $user instanceof User ? $user : null;
    }

    /** A new organization account; persisted, not flushed */
    public function make(string $username, string $displayName): User
    {
        /** @var User $user */
        $user = $this->userManager->createUser();
        $user->setUsername(trim($username));
        $user->setEmail(sprintf('organization+%s@redbook.invalid', preg_replace('/[^a-z0-9._-]+/', '-', mb_strtolower(trim($username)))));
        $this->convert($user, $displayName);
        $this->em->persist($user);
        return $user;
    }

    /**
     * Makes the account an organization's: disabled, a password nothing matches, and its
     * roles dropped (nobody acts as it, so it needs none)
     */
    public function convert(User $user, ?string $displayName = null): void
    {
        if ($user->isHistoric()) {
            throw new RuntimeException(sprintf('"%s" is a historic author\'s account.', $user->getUsername()));
        }
        $user->setOrganization(true);
        $user->setEnabled(false);
        $user->setPassword('!organization');
        $user->setConfirmationToken(null);
        $user->setRoles([]);
        if ($displayName !== null && trim($displayName) !== '') {
            $user->setDisplayName(mb_substr(trim($displayName), 0, 100));
        }
        $this->userManager->updateCanonicalFields($user);
    }
}
