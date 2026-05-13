<?php

declare(strict_types=1);

namespace App\CustomField\Type\Reference;

use App\Entity\Space;
use App\Entity\User;

/**
 * `reference.user` — references one or more users. Scope is "the
 * field's space's effective-users set" — direct space members AND
 * transitive members via group, which is the same union the access
 * extensions use everywhere else (so the picker UI can reuse the
 * existing space-members endpoint).
 */
final class UserReferenceStrategy extends AbstractReferenceStrategy
{
    public function subtype(): string
    {
        return 'user';
    }

    protected function targetClass(): string
    {
        return User::class;
    }

    protected function iriPrefix(): string
    {
        return '/users/';
    }

    protected function displayLabel(object $target): string
    {
        if (!$target instanceof User) {
            return '';
        }
        $name = trim($target->getGivenName() . ' ' . $target->getFamilyName());
        return '' === $name ? $target->getEmail() : $name;
    }

    protected function isInScope(object $target, Space $space): bool
    {
        if (!$target instanceof User) {
            return false;
        }
        foreach ($space->getEffectiveUsers() as $member) {
            if ((string) $member->getId() === (string) $target->getId()) {
                return true;
            }
        }
        return false;
    }
}
