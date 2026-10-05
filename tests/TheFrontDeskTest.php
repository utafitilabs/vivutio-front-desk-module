<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio front desk module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\FrontDesk\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\FrontDesk\Deletion\PlaceDeskDeletion;
use Vivutio\FrontDesk\Entity\DeskStay;
use Vivutio\FrontDesk\Tests\Application\Kernel;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInPlaces;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInStay;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInStays;

/**
 * The front desk, working from whatever stays the installation's packages
 * offer, here stand-ins: a day's arrivals, guests in house and departures at
 * a place; checking in with the units each stay is given, never one held by
 * a guest in house; checking out; no-shows, and taking one back.
 */
final class TheFrontDeskTest extends WebTestCase
{
    private const string BOARD = '/front-desk/lodge/'.StandInPlaces::LODGE;

    private KernelBrowser $browser;
    private MockClock $clock;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $this->clock = new MockClock('2026-10-30 09:00');
        static::getContainer()->set('clock', $this->clock);
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output), $output->fetch());

        StandInStays::$stays = [
            new StandInStay('0199b1c0-0000-7000-8000-0000000a0001', 'SL-0001', 'Mollel party', '2026-10-30', 3, 2),
            new StandInStay('0199b1c0-0000-7000-8000-0000000a0002', 'SL-0002', 'Kimaro family', '2026-10-28', 2, 1),
            new StandInStay('0199b1c0-0000-7000-8000-0000000a0003', 'SL-0003', 'Held party', '2026-10-30', 2, 1, expected: false),
            new StandInStay('0199b1c0-0000-7000-8000-0000000a0004', 'SL-0004', 'Swai couple', '2026-10-30', 1, 1),
        ];
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
    }

    public function testTheDeskListsThePlacesItServes(): void
    {
        $page = $this->browser->request('GET', '/front-desk');

        self::assertResponseIsSuccessful();
        self::assertSame(self::BOARD, $page->selectLink('Vivutio Stand-in Lodge')->attr('href'));
        self::assertSame('/front-desk', $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Front desk')->attr('href'));
    }

    public function testTheBoardShowsTheDaysArrivalsInHouseAndDepartures(): void
    {
        $this->clock->modify('2026-10-28 15:00');
        $this->checkIn('SL-0002', ['tent' => '5'], '2026-10-28');
        $this->clock->modify('2026-10-30 09:00');

        $board = $this->browser->request('GET', self::BOARD.'?day=2026-10-30');

        self::assertSame(['SL-0001', 'SL-0004'], $this->rows($board, 'arrivals'), 'a hold is not expected');
        self::assertSame(['SL-0002'], $this->rows($board, 'departures'));
        self::assertSame(['SL-0002'], $this->rows($board, 'in_house'));
        self::assertStringContainsString('Tent 5', $board->filter('[data-board="in_house"] tr[data-stay="SL-0002"]')->text());
    }

    /** A stay is given as many units of each kind as it takes, and never one a guest in house holds. */
    public function testCheckingInGivesUnitsNeverHeldTwice(): void
    {
        $this->checkIn('SL-0001', ['tent' => '1, 2']);
        self::assertSame(['SL-0001'], $this->rows($this->browser->request('GET', self::BOARD), 'in_house'));

        foreach ([
            [['tent' => '2'], 'Tent 2 is SL-0001\'s'],
            [['tent' => '3, 4'], 'takes 1 Tent'],
            [['tent' => '9'], 'Tent 9'],
            [['tent' => 'the blue one'], 'numbers'],
        ] as [$units, $said]) {
            $page = $this->checkIn('SL-0004', $units, answered: 422);
            self::assertStringContainsString($said, $page->filter('.notice.danger')->text(), json_encode($units, \JSON_THROW_ON_ERROR));
        }
        $this->checkIn('SL-0004', ['tent' => '3']);
    }

    public function testCheckingOutAndNoShows(): void
    {
        $this->checkIn('SL-0001', ['tent' => '1, 2']);
        $this->clock->modify('2026-11-02 10:00');
        $board = $this->browser->request('GET', self::BOARD);
        self::assertSame(['SL-0001'], $this->rows($board, 'departures'));
        $this->browser->submit($board->filter('[data-board="departures"] tr[data-stay="SL-0001"]')->selectButton('Check out')->form());
        self::assertResponseRedirects(self::BOARD.'?day=2026-11-02');
        $board = $this->browser->followRedirect();
        self::assertSame([], $this->rows($board, 'in_house'));
        self::assertSame(['SL-0001'], $this->rows($board, 'left'));

        $this->clock->modify('2026-10-30 18:00');
        $board = $this->browser->request('GET', self::BOARD);
        $this->browser->submit($board->filter('[data-board="arrivals"] tr[data-stay="SL-0004"]')->selectButton('No show')->form());
        $board = $this->browser->followRedirect();
        self::assertSame(['SL-0004'], $this->rows($board, 'no_shows'));
        self::assertNotContains('SL-0004', $this->rows($board, 'arrivals'));

        $this->browser->submit($board->filter('[data-board="no_shows"] tr[data-stay="SL-0004"]')->selectButton('They came after all')->form());
        self::assertContains('SL-0004', $this->rows($this->browser->followRedirect(), 'arrivals'));
    }

    /** Reading the board and acting at the desk are two pairs, each a department must allow; reach applies to the place. */
    public function testStaffActAtTheDeskWhereTheirPairsAllow(): void
    {
        $front = (new Department())->setName('Front office')->setAllows(['front_desk.read']);
        $this->em()->persist($front);
        $this->signedInAs($this->person('Neema', TierEnum::Staff, ['front_desk.read', 'front_desk.record'], $front));

        $board = $this->browser->request('GET', self::BOARD);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $board->filter('form[method="post"]'), 'recording is not allowed by the department');
        $this->browser->request('POST', self::BOARD.'/stays/lodge_booking/0199b1c0-0000-7000-8000-0000000a0001/check-in', ['units' => ['tent' => '1, 2']]);
        self::assertResponseStatusCodeSame(403);

        $this->browser->request('GET', '/front-desk/lodge/0199b1c0-0000-7000-8000-00000000dfff');
        self::assertResponseStatusCodeSame(404);
    }

    /** The module ships the migration its mapping needs, and nothing is left for a diff to write. */
    /** A place deleted by a Super Admin takes the desk's records there with it, counted on the delete page. */
    public function testThePlacesDeskRecordsGoWithIt(): void
    {
        $em = $this->em();
        $em->persist(new DeskStay(StandInStays::KIND, '0199b1c0-0000-7000-8000-0000000a0201', StandInPlaces::KIND, StandInPlaces::LODGE, 'SL-0001'));
        $em->persist(new DeskStay(StandInStays::KIND, '0199b1c0-0000-7000-8000-0000000a0202', StandInPlaces::KIND, StandInPlaces::LODGE, 'SL-0002'));
        $em->flush();

        $deletion = new PlaceDeskDeletion($em);
        self::assertTrue($deletion->supports(StandInPlaces::lodge()));
        self::assertFalse($deletion->supports(new \stdClass()));
        self::assertNull($deletion->describe(StandInPlaces::lodge()));
        self::assertSame(['2 front desk records'], array_map(static fn ($line): string => $line->text(), $deletion->whatGoes(StandInPlaces::lodge())));

        $deletion->delete(StandInPlaces::lodge());
        $em->flush();
        self::assertCount(0, $em->getRepository(DeskStay::class)->findAll());
    }

    public function testTheMigrationsBuildWhatTheMappingDescribes(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:schema:validate', '--skip-property-types' => true]), $output), $output->fetch());
    }

    /**
     * @param array<string, string> $units
     */
    private function checkIn(string $reference, array $units, string $day = '2026-10-30', int $answered = 302): Crawler
    {
        $board = $this->browser->request('GET', self::BOARD.'?day='.$day);
        $form = $board->filter('[data-board="arrivals"] tr[data-stay="'.$reference.'"]')->selectButton('Check in')->form();
        $values = [];
        foreach ($units as $type => $numbers) {
            $values['units['.$type.']'] = $numbers;
        }
        $page = $this->browser->submit($form->setValues($values));
        self::assertResponseStatusCodeSame($answered);

        return $page;
    }

    /**
     * @return list<string>
     */
    private function rows(Crawler $board, string $section): array
    {
        return $board->filter('[data-board="'.$section.'"] tr[data-stay]')->each(static fn (Crawler $row): string => (string) $row->attr('data-stay'));
    }

    /**
     * @param list<string>|null $grants
     */
    private function person(string $name, TierEnum $tier, ?array $grants = null, ?Department $department = null): User
    {
        $position = null;
        if (null !== $grants) {
            $position = (new Position())->setName($name.'\'s seat')->setGrants($grants);
            $this->em()->persist($position);
        }

        $user = (new User())
            ->setEmail(strtolower($name).'@vivutio-camps.example')
            ->setFirstName($name)
            ->setLastName('Kimaro')
            ->setTier($tier)
            ->setPosition($position)
            ->setDepartment($department)
            ->setPassword('a hash, never a password');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function signedInAs(User $user): void
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
