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

namespace Vivutio\FrontDesk\Shell;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Shell\DashboardCard;
use Vivutio\Contracts\Shell\DashboardFigure;
use Vivutio\Contracts\Shell\DashboardRow;
use Vivutio\Contracts\Shell\DashboardSourceInterface;
use Vivutio\Contracts\Stay\StayInterface;
use Vivutio\FrontDesk\Controller\FrontDeskController;
use Vivutio\FrontDesk\Entity\DeskStay;
use Vivutio\FrontDesk\Model\DeskBoard;
use Vivutio\FrontDesk\Service\FrontDeskService;

/**
 * The desk on the organization's dashboard, today, over every place the
 * person may open: stays in house tonight and the day's arrivals in the band,
 * and the day's arrivals and departures as cards. A stay does not say how
 * many guests it brings, so stays are counted.
 */
final readonly class FrontDeskDashboard implements DashboardSourceInterface
{
    private const string KICKER = 'Front desk';
    private const string CAPTION = 'every place';
    private const string DOOR = 'The front desk';

    public function __construct(
        private FrontDeskService $desk,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function figures(): iterable
    {
        $boards = $this->boards();
        if (null === $boards) {
            return [];
        }

        $tonight = 0;
        $places = 0;
        foreach ($boards as $board) {
            $staying = \count(array_filter($board->inHouse, static fn (array $row): bool => $row['stay']->getDeparture() > $board->day));
            $tonight += $staying;
            $places += $staying > 0 ? 1 : 0;
        }
        [$expected, $in] = $this->arrivals($boards);

        return [
            new DashboardFigure('In house tonight', (string) $tonight, 0 === $places ? 'stays' : \sprintf('%s, in %d %s', 1 === $tonight ? 'stay' : 'stays', $places, 1 === $places ? 'place' : 'places'), FrontDeskController::READ, 10),
            new DashboardFigure('Arriving today', (string) (\count($expected) + \count($in)), [] === $in ? '' : \sprintf('%d checked in', \count($in)), FrontDeskController::READ, 20),
        ];
    }

    public function cards(): iterable
    {
        $boards = $this->boards();
        if (null === $boards) {
            return [];
        }

        [$expected, $in] = $this->arrivals($boards);
        $arriving = [
            ...array_map(static fn (array $row): DashboardRow => self::arrival($row, 'Expected', ''), $expected),
            ...array_map(static fn (array $row): DashboardRow => self::arrival($row, 'Checked in', 'ok'), $in),
        ];

        $leaving = [];
        foreach ($boards as $board) {
            foreach ($board->departures as $row) {
                $leaving[] = self::departure($row, $board->place, 'In house', '');
            }
        }
        foreach ($boards as $board) {
            foreach ($board->left as $row) {
                $leaving[] = self::departure($row, $board->place, 'Checked out', 'ok');
            }
        }

        return [
            new DashboardCard('Arriving today', self::KICKER, self::CAPTION, \array_slice($arriving, 0, DashboardCard::MOST_ROWS), \count($arriving), 'Nobody arrives today', self::DOOR, FrontDeskController::PLACES, FrontDeskController::READ, order: 10),
            new DashboardCard('Leaving today', self::KICKER, self::CAPTION, \array_slice($leaving, 0, DashboardCard::MOST_ROWS), \count($leaving), 'Nobody leaves today', self::DOOR, FrontDeskController::PLACES, FrontDeskController::READ, order: 30),
        ];
    }

    /**
     * Today's board at each place the person may open, or null for a person
     * who may not read the desk at all.
     *
     * @return list<DeskBoard>|null
     */
    private function boards(): ?array
    {
        if (!$this->authorization->isGranted(FrontDeskController::READ)) {
            return null;
        }

        $today = $this->desk->today();
        $boards = [];
        foreach ($this->desk->places() as $place) {
            if ($this->authorization->isGranted(FrontDeskController::READ, $place)) {
                $boards[] = $this->desk->board($place, $today);
            }
        }

        return $boards;
    }

    /**
     * The day's arrivals: those still expected, and those checked in today.
     *
     * @param list<DeskBoard> $boards
     *
     * @return array{0: list<array{stay: StayInterface, place: PlaceInterface}>, 1: list<array{stay: StayInterface, place: PlaceInterface}>}
     */
    private function arrivals(array $boards): array
    {
        $expected = $in = [];
        foreach ($boards as $board) {
            foreach ($board->arrivals as $row) {
                $expected[] = ['stay' => $row['stay'], 'place' => $board->place];
            }
            foreach ($board->inHouse as $row) {
                if (self::checkedInOn($row['desk'], $board->day)) {
                    $in[] = ['stay' => $row['stay'], 'place' => $board->place];
                }
            }
        }

        return [$expected, $in];
    }

    private static function checkedInOn(?DeskStay $desk, \DateTimeImmutable $day): bool
    {
        return $desk?->getCheckedInAt()?->format('Y-m-d') === $day->format('Y-m-d');
    }

    /**
     * @param array{stay: StayInterface, place: PlaceInterface} $row
     */
    private static function arrival(array $row, string $chip, string $tone): DashboardRow
    {
        $nights = (int) $row['stay']->getArrival()->diff($row['stay']->getDeparture())->days;

        return new DashboardRow($row['stay']->getGuest(), $row['place']->getName(), [self::units($row['stay']), \sprintf('%d %s', $nights, 1 === $nights ? 'night' : 'nights')], $chip, $tone);
    }

    /**
     * @param array{stay: StayInterface, desk: DeskStay|null} $row
     */
    private static function departure(array $row, PlaceInterface $place, string $chip, string $tone): DashboardRow
    {
        return new DashboardRow($row['stay']->getGuest(), $place->getName(), [self::units($row['stay'])], $chip, $tone);
    }

    /** "Garden room × 2", each kind the stay takes. */
    private static function units(StayInterface $stay): string
    {
        return implode(', ', array_map(static fn (array $unit): string => 1 === $unit['count'] ? $unit['name'] : \sprintf('%s × %d', $unit['name'], $unit['count']), $stay->getUnits()));
    }
}
