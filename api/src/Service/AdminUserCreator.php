<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Provisions a ready-to-sign-in account server-side (#admin-user-create).
 *
 * Signup normally makes an account wait on two gates — email confirmation
 * (`ROLE_UNVERIFIED`) and, while the instance is in waitlist mode,
 * `ROLE_WAITLISTED`. An admin creating an account vouches for the address, so
 * this clears both: the user can sign in and use the app immediately. That's
 * what makes it usable for manual customer onboarding, demos, support — and for
 * standing up a test account on an instance whose signups are closed.
 *
 * Shared by {@see \App\Controller\AdminUserController} and
 * {@see \App\Command\UserCreateCommand} so the HTTP and CLI paths produce
 * identical accounts.
 */
final class AdminUserCreator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PersonalSpaceProvisioner $personalSpaceProvisioner,
        private readonly SpaceRoleSeeder $roleSeeder,
    ) {
    }

    /**
     * @param string|null $plainPassword null = generate a strong one
     * @param bool        $promoted      clear the waitlist gate (ROLE_USER now)
     * @param string|null $spaceName     also create a shared space, user as admin
     *
     * @return array{user: User, password: string, space: Space|null}
     *
     * @throws \InvalidArgumentException when the email is already taken
     */
    public function create(
        string $email,
        string $givenName,
        string $familyName,
        ?string $plainPassword = null,
        bool $promoted = true,
        ?string $spaceName = null,
    ): array {
        $email = trim($email);
        if (null !== $this->em->getRepository(User::class)->findOneBy(['email' => $email])) {
            throw new \InvalidArgumentException(sprintf('An account already exists for "%s".', $email));
        }

        $password = null !== $plainPassword && '' !== $plainPassword
            ? $plainPassword
            : self::generatePassword();

        $user = (new User())
            ->setEmail($email)
            ->setGivenName(trim($givenName))
            ->setFamilyName(trim($familyName));
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        // Both gates cleared — see the class docblock.
        $user->setEmailVerified(true);
        $user->setWaitlisted(!$promoted);

        $this->em->persist($user);
        $this->em->flush();

        $this->personalSpaceProvisioner->provision($user);

        $space = null;
        if (null !== $spaceName && '' !== trim($spaceName)) {
            $space = (new Space())
                ->setName(trim($spaceName))
                ->setIsPersonal(false)
                ->setVisibility(Space::VISIBILITY_PRIVATE)
                ->setCreatedBy($user);
            $space->addUserMembership(
                (new SpaceMembership())->setUser($user)->setRole(Space::ROLE_ADMIN),
            );
            $this->em->persist($space);
            $this->roleSeeder->seed($space);
            $this->em->flush();
        }

        return ['user' => $user, 'password' => $password, 'space' => $space];
    }

    /**
     * A strong random password. Returned to the caller once (never stored in
     * plaintext) — same one-shot shape as the API-token reveal.
     */
    public static function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
}
