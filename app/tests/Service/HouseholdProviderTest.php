<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\User;
use App\Enum\DaySlot;
use App\Enum\Weekday;
use App\Service\HouseholdProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HouseholdProviderTest extends KernelTestCase
{
    public function testCreatesTheMissingHousehold(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('sans-foyer@example.com')->setPassword('x')->setVerified(true);
        $em->persist($user);
        $em->flush();

        $household = self::getContainer()->get(HouseholdProvider::class)->forUser($user);

        self::assertNotNull($household->getId());
        self::assertCount(28, $household->getTargets());
    }

    public function testRepairsAHouseholdWithoutTargets(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('incomplet@example.com')->setPassword('x')->setVerified(true);
        $em->persist($user);
        $em->persist(new Household($user));
        $em->flush();
        $em->getConnection()->executeStatement('DELETE FROM heating_target');
        $em->clear();

        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'incomplet@example.com']);
        self::assertNotNull($reloaded);
        $household = self::getContainer()->get(HouseholdProvider::class)->forUser($reloaded);
        $em->clear();

        self::assertSame(28, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM heating_target'));
        self::assertSame(19.0, $household->targetFor(Weekday::Friday, DaySlot::Morning)->getTemperature());
    }
}
