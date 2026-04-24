<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Service\AvatarColorService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private AvatarColorService $colorService,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setEmail('admin@aura.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setGivenName('Ada');
        $admin->setFamilyName('Admin');
        $admin->setPersonalizedColor($this->colorService->pick());
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $manager->persist($admin);

        $user = new User();
        $user->setEmail('user@aura.test');
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Uma');
        $user->setFamilyName('User');
        $user->setPersonalizedColor($this->colorService->pick());
        $user->setPassword($this->passwordHasher->hashPassword($user, 'user123'));
        $manager->persist($user);

        $manager->flush();
    }
}
