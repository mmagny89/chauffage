<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Household;
use App\Entity\Place;
use App\Exception\PlaceRejectedException;
use App\Repository\PlaceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les lieux déclarés d'un foyer : ceux qu'il faut renseigner à chaque relevé.
 */
final readonly class PlaceManager
{
    public const MAX_NAME_LENGTH = 80;
    public const MAX_PLACES = 30;

    public function __construct(
        private PlaceRepository $places,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PlaceRejectedException
     */
    public function add(Household $household, string $name): Place
    {
        $name = self::normalize($name);
        $this->assertValidName($name);

        if (null !== $this->places->findOneByHouseholdAndName($household, $name)) {
            throw new PlaceRejectedException('Ce lieu existe déjà dans votre foyer.');
        }
        if (\count($this->places->findByHousehold($household)) >= self::MAX_PLACES) {
            throw new PlaceRejectedException(\sprintf('Un foyer ne peut pas compter plus de %d lieux.', self::MAX_PLACES));
        }

        $place = new Place($household, $name);
        $this->entityManager->persist($place);
        $this->entityManager->flush();

        return $place;
    }

    /**
     * @throws PlaceRejectedException
     */
    public function rename(Place $place, string $name): void
    {
        $name = self::normalize($name);
        $this->assertValidName($name);

        $existing = $this->places->findOneByHouseholdAndName($place->getHousehold(), $name);
        if (null !== $existing && $existing !== $place) {
            throw new PlaceRejectedException('Ce lieu existe déjà dans votre foyer.');
        }

        $place->setName($name);
        $this->entityManager->flush();
    }

    /**
     * Supprime le lieu et, en cascade, tous ses relevés.
     */
    public function delete(Place $place): void
    {
        $this->entityManager->remove($place);
        $this->entityManager->flush();
    }

    private static function normalize(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function assertValidName(string $name): void
    {
        if ('' === $name) {
            throw new PlaceRejectedException('Indiquez le nom du lieu.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new PlaceRejectedException(\sprintf('Le nom du lieu ne doit pas dépasser %d caractères.', self::MAX_NAME_LENGTH));
        }
    }
}
