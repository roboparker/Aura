<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Property-level constraint on plaintext password fields. Delegates to
 * Symfony's `PasswordStrength` but reads its `minScore` floor from the
 * `app.password_min_strength` parameter, so dev/test can run at
 * `STRENGTH_VERY_WEAK` (0) while staging/prod stay at `STRENGTH_MEDIUM` (2).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PasswordPolicy extends Constraint
{
    public string $message = 'This password is too easy to guess — try mixing in more characters or making it longer.';
}
