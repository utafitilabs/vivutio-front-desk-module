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
use Vivutio\FrontDesk\Entity\DeskStay;
use Vivutio\FrontDesk\Tests\Application\Kernel;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInPlaces;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInReach;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInStay;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInStays;

/**
 * The desk on the organization's dashboard, over every place the person may
 * open, today: guests in house tonight and arrivals in the band, and the
 * day's arrivals and departures as two cards, each with one door to the desk.
 */
final class TheFrontDeskDashboardTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-10-30 09:00'));
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

        StandInReach::$outOfReach = [];
        StandInStays::$stays = [
            new StandInStay('0199b1c0-0000-7000-8000-0000000b0001', 'SL-0001', 'Mollel party', '2026-10-30', 3, 2),
            new StandInStay('0199b1c0-0000-7000-8000-0000000b0002', 'SL-0002', 'Kimaro family', '2026-10-28', 2, 1),
            new StandInStay('0199b1c0-0000-7000-8000-0000000b0003', 'SL-0003', 'Held party', '2026-10-30', 2, 1, expected: false),
            new StandInStay('0199b1c0-0000-7000-8000-0000000b0004', 'SL-0004', 'Swai couple', '2026-10-30', 1, 1),
            new StandInStay('0199b1c0-0000-7000-8000-0000000b0005', 'SL-0005', 'Lyimo family', '2026-10-27', 3, 1),
            new StandInStay('0199b1c0-0000-7000-8000-0000000b0006', 'SL-0006', 'Mushi party', '2026-10-29', 4, 1),
            new StandInStay('0199b1c0-0000-7000-8000-0000000b0007', 'SC-0001', 'Kweka party', '2026-10-30', 1, 1, place: StandInPlaces::CAMP),
            new StandInStay('0199b1c0-0000-7000-8000-0000000b0008', 'SC-0002', 'Haddad party', '2026-10-29', 2, 2, place: StandInPlaces::CAMP),
        ];
        $this->desk('SL-0002', '2026-10-28 14:00');
        $this->desk('SL-0004', '2026-10-30 08:30');
        $this->desk('SL-0005', '2026-10-27 15:00', '2026-10-30 08:00');
        $this->desk('SL-0006', '2026-10-29 16:00');
        $this->desk('SC-0002', '2026-10-29 12:00', place: StandInPlaces::CAMP);
    }

    /** In house tonight: staying past today, those who came today among them; arriving: expected and already in. */
    public function testTheBandCountsTonightsStaysAndTodaysArrivals(): void
    {
        $page = $this->dashboard($this->person('Baraka', TierEnum::Admin));

        self::assertSame(['In house tonight 3 stays, in 2 places', 'Arriving today 3 1 checked in'], $this->figures($page));
    }

    /** Expected first, then those already in; each with its place, its units and its nights. */
    public function testArrivingTodayListsTheDaysArrivalsAcrossPlaces(): void
    {
        $card = $this->card($this->dashboard($this->person('Baraka', TierEnum::Admin)), 'Arriving today');

        self::assertSame('Front desk · every place', self::words($card->filter('.panel-head p')));
        self::assertSame([
            'Mollel party Vivutio Stand-in Lodge Tent × 2 3 nights Expected',
            'Kweka party Vivutio Stand-in Camp Tent 1 night Expected',
            'Swai couple Vivutio Stand-in Lodge Tent 1 night Checked in',
        ], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame(['chip', 'chip', 'chip ok'], $card->filter('.chip')->each(static fn (Crawler $chip): string => (string) $chip->attr('class')));
        self::assertSame('3 of 3', self::words($card->filter('.panel-foot .state')));
        self::assertSame('/front-desk', $card->filter('.panel-foot a.more')->attr('href'));
        self::assertSame('The front desk', self::words($card->filter('.panel-foot a.more')));
    }

    /** Those due out today and still in house first, then those already gone. */
    public function testLeavingTodayListsTheDaysDepartures(): void
    {
        $card = $this->card($this->dashboard($this->person('Baraka', TierEnum::Admin)), 'Leaving today');

        self::assertSame('Front desk · every place', self::words($card->filter('.panel-head p')));
        self::assertSame([
            'Kimaro family Vivutio Stand-in Lodge Tent In house',
            'Lyimo family Vivutio Stand-in Lodge Tent Checked out',
        ], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame(['chip', 'chip ok'], $card->filter('.chip')->each(static fn (Crawler $chip): string => (string) $chip->attr('class')));
        self::assertSame('2 of 2', self::words($card->filter('.panel-foot .state')));
        self::assertSame('/front-desk', $card->filter('.panel-foot a.more')->attr('href'));
    }

    /** A card shows five, and says how many there are in all. */
    public function testACardShowsFiveOfMany(): void
    {
        $stays = [];
        foreach (range(1, 7) as $n) {
            $stays[] = new StandInStay(\sprintf('0199b1c0-0000-7000-8000-0000000c%04d', $n), \sprintf('SL-01%02d', $n), \sprintf('Party %d', $n), '2026-10-30', 2, 1);
        }
        StandInStays::$stays = $stays;

        $card = $this->card($this->dashboard($this->person('Baraka', TierEnum::Admin)), 'Arriving today');

        self::assertCount(5, $card->filter('tbody tr'));
        self::assertSame('5 of 7', self::words($card->filter('.panel-foot .state')));
    }

    public function testADayWithNobodyComingOrGoingSaysSo(): void
    {
        StandInStays::$stays = [];

        $page = $this->dashboard($this->person('Baraka', TierEnum::Admin));

        self::assertSame(['In house tonight 0 stays', 'Arriving today 0'], $this->figures($page));
        self::assertSame('Nobody arrives today', self::words($this->card($page, 'Arriving today')->filter('[data-card-empty]')));
        self::assertSame('Nobody leaves today', self::words($this->card($page, 'Leaving today')->filter('[data-card-empty]')));
    }

    /** A place out of the person's reach is neither counted nor listed. */
    public function testOnlyThePlacesThePersonMayOpenCount(): void
    {
        StandInReach::$outOfReach = [StandInPlaces::CAMP];
        $page = $this->dashboard($this->staff('Neema', ['dashboard.read', 'front_desk.read']));

        self::assertSame(['In house tonight 2 stays, in 1 place', 'Arriving today 2 1 checked in'], $this->figures($page));
        self::assertSame(['Mollel party', 'Swai couple'], $this->card($page, 'Arriving today')->filter('tbody tr b')->each(static fn (Crawler $b): string => $b->text()));
    }

    public function testNothingWithoutTheDesksReadPair(): void
    {
        $page = $this->dashboard($this->staff('Juma', ['dashboard.read']));

        self::assertSame([], $this->figures($page));
        self::assertCount(0, $page->filter('[data-cards] .panel-head p:contains("Front desk")'));
    }

    private function desk(string $reference, string $checkedIn, ?string $checkedOut = null, string $place = StandInPlaces::LODGE): void
    {
        $stay = array_values(array_filter(StandInStays::$stays, static fn (StandInStay $stay): bool => $stay->getReference() === $reference))[0];
        $desk = (new DeskStay(StandInStays::KIND, $stay->getStayId(), StandInPlaces::KIND, $place, $reference))
            ->setCheckedInAt(new \DateTimeImmutable($checkedIn))
            ->setCheckedOutAt(null === $checkedOut ? null : new \DateTimeImmutable($checkedOut))
            ->setUnits([['type' => StandInStays::TENT, 'name' => 'Tent', 'number' => 1]]);
        $this->em()->persist($desk);
        $this->em()->flush();
    }

    private function dashboard(User $user): Crawler
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
        $page = $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-dashboard="organization"]'));

        return $page;
    }

    /**
     * @return list<string>
     */
    private function figures(Crawler $page): array
    {
        return $page->filter('.band .f')->each(static fn (Crawler $f): string => self::words($f));
    }

    private function card(Crawler $page, string $title): Crawler
    {
        $card = $page->filter('[data-cards] .panel')->reduce(static fn (Crawler $panel): bool => self::words($panel->filter('.panel-head h2')) === $title);
        self::assertCount(1, $card, $title);

        return $card;
    }

    /** The words of a node, each element's own apart, as a reader sees them. */
    private static function words(Crawler $node): string
    {
        $words = [];
        foreach ($node as $element) {
            foreach ((new \DOMXPath($element->ownerDocument ?? new \DOMDocument()))->query('.//text()', $element) ?: [] as $text) {
                if ($text instanceof \DOMText) {
                    $words[] = trim((string) preg_replace('/\s+/', ' ', $text->textContent));
                }
            }
        }

        return implode(' ', array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    /**
     * @param list<string> $grants
     */
    private function staff(string $name, array $grants): User
    {
        $front = (new Department())->setName($name.'\'s front office')->setAllows(['front_desk.read']);
        $this->em()->persist($front);

        return $this->person($name, TierEnum::Staff, $grants, $front);
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

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
