<?php

namespace App\Tests\Validator;

use App\Validator\PasswordPolicy;
use App\Validator\PasswordPolicyValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * Unit-tests the env-driven password strength gate. Asserts that the
 * MEDIUM (2) floor that staging + production run at still catches the
 * textbook weak passwords ("password", "12345678", "Password1!") even
 * though the test env itself runs at VERY_WEAK (0). When the floor is
 * relaxed (dev/test), the validator passes everything.
 *
 * @extends ConstraintValidatorTestCase<PasswordPolicyValidator>
 */
class PasswordPolicyValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): PasswordPolicyValidator
    {
        return new PasswordPolicyValidator(minStrength: 2);
    }

    public function testRejectsWeakPasswordAtMediumFloor(): void
    {
        $this->validator->validate('password', new PasswordPolicy());

        $this->buildViolation((new PasswordPolicy())->message)
            ->assertRaised();
    }

    public function testRejectsCommonNumericPasswordAtMediumFloor(): void
    {
        $this->validator->validate('12345678', new PasswordPolicy());

        $this->buildViolation((new PasswordPolicy())->message)
            ->assertRaised();
    }

    public function testAcceptsStrongPasswordAtMediumFloor(): void
    {
        $this->validator->validate('Tr0ub4dor&3xample!', new PasswordPolicy());

        $this->assertNoViolation();
    }

    public function testIgnoresNullAndEmptyStrings(): void
    {
        $this->validator->validate(null, new PasswordPolicy());
        $this->validator->validate('', new PasswordPolicy());

        $this->assertNoViolation();
    }

    public function testRelaxedFloorAcceptsAnything(): void
    {
        // Mirrors what dev + test deployments run at — the validator
        // becomes a no-op so engineers don't need to think up a 12-char
        // password every time fixtures rebuild.
        $relaxed = new PasswordPolicyValidator(minStrength: 0);
        $relaxed->initialize($this->context);

        $relaxed->validate('password', new PasswordPolicy());

        $this->assertNoViolation();
    }
}
