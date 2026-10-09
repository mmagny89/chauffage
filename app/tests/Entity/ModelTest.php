<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ModelTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testNewHouseholdHasADefaultTargetPerWeekdayAndSlot(): void
    {
        $household = new Household($this->user());

        self::assertCount(\count(Weekday::cases()) * \count(DaySlot::cases()), $household->getTargets());
        foreach (Weekday::cases() as $day) {
            self::assertSame(17.0, $household->targetFor($day, DaySlot::Night)->getTemperature());
            self::assertSame(20.0, $household->targetFor($day, DaySlot::Evening)->getTemperature());
        }
    }

    public function testHouseholdLocationIsStoredAsDecimals(): void
    {
        $household = new Household($this->user());
        $household->locate('Lyon', 45.75, 4.85, 'Europe/Paris');
        $this->em->persist($household);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Household::class, $household->getId());

        self::assertSame('Lyon', $reloaded->getCity());
        self::assertSame(45.75, $reloaded->getLatitude());
        self::assertSame(4.85, $reloaded->getLongitude());
        self::assertCount(28, $reloaded->getTargets());
    }

    public function testReadingComputesDeltaAndSlot(): void
    {
        $reading = new Reading($this->place(), new \DateTimeImmutable('2026-10-09 07:15'), 4.5, 18.0);

        self::assertSame(13.5, $reading->getDelta());
        self::assertSame(DaySlot::Morning, $reading->getSlot());
    }

    public function testNegativeOutdoorTemperatureKeepsItsSign(): void
    {
        $place = $this->place();
        $this->em->persist(new Reading($place, new \DateTimeImmutable('2026-01-10 03:00'), -7.5, 16.0));
        $this->em->flush();
        $this->em->clear();

        $reading = $this->em->getRepository(Reading::class)->findOneBy([]);

        self::assertSame(-7.5, $reading->getOutdoorTemperature());
        self::assertSame(23.5, $reading->getDelta());
    }

    public function testImplausibleTemperaturesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Reading($this->place(), new \DateTimeImmutable('2026-10-09 07:00'), 99.0, 18.0);
    }

    public function testOneReadingPerPlaceAndInstant(): void
    {
        $place = $this->place();
        $this->em->persist(new Reading($place, new \DateTimeImmutable('2026-10-09 07:00'), 5.0, 18.0));
        $this->em->flush();

        $duplicate = new Reading($place, new \DateTimeImmutable('2026-10-09 07:00'), 6.0, 19.0);
        self::assertCount(1, self::getContainer()->get(ValidatorInterface::class)->validate($duplicate));

        $this->em->persist($duplicate);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testPlaceNameIsUniquePerHousehold(): void
    {
        $place = $this->place();

        $duplicate = new Place($place->getHousehold(), '  Salon ');

        self::assertCount(1, self::getContainer()->get(ValidatorInterface::class)->validate($duplicate));
    }

    public function testDeletingTheAccountDeletesEverythingBelow(): void
    {
        $place = $this->place();
        $this->em->persist(new Reading($place, new \DateTimeImmutable('2026-10-09 07:00'), 5.0, 18.0));
        $this->em->flush();

        $this->em->getConnection()->executeStatement('DELETE FROM app_user');

        foreach (['household', 'heating_target', 'place', 'reading'] as $table) {
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
    }

    private function user(): User
    {
        $user = (new User())->setEmail('modele-'.bin2hex(random_bytes(4)).'@example.com')->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function place(): Place
    {
        $household = new Household($this->user());
        $place = new Place($household, 'Salon');
        $this->em->persist($household);
        $this->em->persist($place);
        $this->em->flush();

        return $place;
    }
}
