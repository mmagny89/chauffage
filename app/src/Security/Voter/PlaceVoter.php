<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Place;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un lieu n'est modifiable que par le compte dont le foyer le porte.
 *
 * @extends Voter<string, Place>
 */
final class PlaceVoter extends Voter
{
    public const MANAGE = 'PLACE_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof Place;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getHousehold()->getUser() === $user;
    }
}
