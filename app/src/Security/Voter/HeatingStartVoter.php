<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\HeatingStart;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un allumage n'appartient qu'au compte dont le foyer porte sa pièce.
 *
 * @extends Voter<string, HeatingStart>
 */
final class HeatingStartVoter extends Voter
{
    public const DELETE = 'HEATING_START_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::DELETE === $attribute && $subject instanceof HeatingStart;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getPlace()->getHousehold()->getUser() === $user;
    }
}
