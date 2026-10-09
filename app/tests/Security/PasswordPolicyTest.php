<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PasswordPolicyTest extends KernelTestCase
{
    #[DataProvider('refusedPasswords')]
    public function testWeakPasswordsAreRefused(string $password): void
    {
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($password, PasswordPolicy::constraints());

        self::assertGreaterThan(0, \count($violations), \sprintf('« %s » aurait dû être refusé.', $password));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPasswords(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => ['Court1!'];
        yield 'douze chiffres' => ['123456789012'];
        yield 'répétition' => ['aaaaaaaaaaaaaaaa'];
    }

    public function testPassphraseIsAccepted(): void
    {
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate('cheval agrafeuse nuage tricot', PasswordPolicy::constraints());

        self::assertCount(0, $violations);
    }

    public function testPolicyChecksKnownLeaks(): void
    {
        $classes = array_map(static fn (object $constraint): string => $constraint::class, PasswordPolicy::constraints());

        self::assertContains(NotCompromisedPassword::class, $classes);
    }
}
