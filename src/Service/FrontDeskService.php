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

namespace Vivutio\FrontDesk\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Vivutio\Bundle\IdentityBundle\Service\PlaceDirectoryService;
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Stay\StayInterface;
use Vivutio\Contracts\Stay\StaySourceInterface;
use Vivutio\Contracts\Stay\UnitSourceInterface;
use Vivutio\FrontDesk\Entity\DeskStay;
use Vivutio\FrontDesk\Exception\DeskRefusedException;
use Vivutio\FrontDesk\Model\DeskBoard;
use Vivutio\FrontDesk\Repository\DeskStayRepository;

/**
 * The desk, from every stay the installation's packages offer. A stay is
 * checked in from its arrival day until before it leaves, given as many units
 * of each kind as it takes and never one a guest in house holds; it is
 * checked out once in; a stay expected and not in is marked a no-show, and
 * the mark is taken back if they come after all.
 */
final readonly class FrontDeskService
{
    /**
     * @param iterable<StaySourceInterface> $stays
     * @param iterable<UnitSourceInterface> $unitSources
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private DeskStayRepository $desk,
        private PlaceDirectoryService $places,
        private iterable $stays,
        private iterable $unitSources,
    ) {
    }

    /**
     * The places the desk serves: those a package says what units they have.
     *
     * @return list<PlaceInterface>
     */
    public function places(): array
    {
        $served = [];
        foreach ($this->places->groups() as $group) {
            foreach ($group->places as $place) {
                if (null !== $this->units($place)) {
                    $served[] = $place;
                }
            }
        }

        return $served;
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }

    public function board(PlaceInterface $place, \DateTimeImmutable $day): DeskBoard
    {
        $arrivals = $inHouse = $departures = $left = $noShows = [];
        foreach ($this->staysAt($place, $day->modify('-30 days'), $day) as $stay) {
            $desk = $this->desk->findOneByStay($stay);
            $row = ['stay' => $stay, 'desk' => $desk];
            if (null !== $desk && $desk->isInHouse()) {
                $inHouse[] = $row;
                if ($stay->getDeparture() <= $day) {
                    $departures[] = $row;
                }
            } elseif (null !== $desk && null !== $desk->getCheckedOutAt()) {
                if ($desk->getCheckedOutAt()->format('Y-m-d') === $day->format('Y-m-d')) {
                    $left[] = $row;
                }
            } elseif (null !== $desk && null !== $desk->getNoShowAt()) {
                if ($stay->getArrival() <= $day && $stay->getDeparture() > $day) {
                    $noShows[] = $row;
                }
            } elseif ($stay->isExpected() && $stay->getArrival() <= $day && $stay->getDeparture() > $day) {
                $arrivals[] = $row;
            }
        }

        return new DeskBoard($place, $day, self::sorted($arrivals), self::sorted($inHouse), self::sorted($departures), self::sorted($left), self::sorted($noShows), $this->units($place) ?? []);
    }

    /**
     * @param list<array{stay: StayInterface, desk: DeskStay|null}> $rows
     *
     * @return list<array{stay: StayInterface, desk: DeskStay|null}>
     */
    private static function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $a['stay']->getReference() <=> $b['stay']->getReference());

        return $rows;
    }

    /**
     * The stay a package offers by its kind and id, at this place.
     *
     * @throws DeskRefusedException
     */
    public function stay(PlaceInterface $place, string $kind, string $id): StayInterface
    {
        foreach ($this->stays as $source) {
            if ($source->kind() === $kind) {
                $stay = $source->find($id);
                $at = $stay?->placedAt();
                if (null !== $stay && null !== $at && $at->getPlaceKind() === $place->getPlaceKind() && $at->getPlaceId() === $place->getPlaceId()) {
                    return $stay;
                }
            }
        }

        throw new DeskRefusedException('stay', 'There is no such stay here.');
    }

    /**
     * @param array<string, string> $typed unit numbers by kind, as typed: "1, 2"
     *
     * @throws DeskRefusedException
     */
    public function checkIn(PlaceInterface $place, StayInterface $stay, array $typed): void
    {
        $today = $this->today();
        $desk = $this->desk->findOneByStay($stay);
        if (!$stay->isExpected()) {
            throw new DeskRefusedException('stay', \sprintf('%s is not expected: it is held or cancelled.', $stay->getReference()));
        }
        if (null !== $desk && (null !== $desk->getCheckedInAt() || null !== $desk->getNoShowAt())) {
            throw new DeskRefusedException('stay', \sprintf('%s is %s already.', $stay->getReference(), null !== $desk->getNoShowAt() ? 'a no-show' : 'checked in'));
        }
        if ($today < $stay->getArrival() || $today >= $stay->getDeparture()) {
            throw new DeskRefusedException('stay', \sprintf('%s is checked in from %s to before %s.', $stay->getReference(), $stay->getArrival()->format('j M'), $stay->getDeparture()->format('j M Y')));
        }

        $units = $this->given($place, $stay, $typed);
        $desk ??= $this->keep($place, $stay);
        $desk->setCheckedInAt($this->clock->now())->setUnits($units);
        $this->entityManager->flush();
    }

    /**
     * @throws DeskRefusedException
     */
    public function checkOut(StayInterface $stay): void
    {
        $desk = $this->desk->findOneByStay($stay);
        if (null === $desk || !$desk->isInHouse()) {
            throw new DeskRefusedException('stay', \sprintf('%s is not in house.', $stay->getReference()));
        }
        $desk->setCheckedOutAt($this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * @throws DeskRefusedException
     */
    public function noShow(PlaceInterface $place, StayInterface $stay): void
    {
        $desk = $this->desk->findOneByStay($stay);
        if (!$stay->isExpected() || (null !== $desk && (null !== $desk->getCheckedInAt() || null !== $desk->getNoShowAt()))) {
            throw new DeskRefusedException('stay', \sprintf('%s is not an arrival still expected.', $stay->getReference()));
        }
        if ($this->today() < $stay->getArrival()) {
            throw new DeskRefusedException('stay', \sprintf('%s arrives on %s: it is not a no-show yet.', $stay->getReference(), $stay->getArrival()->format('j M Y')));
        }
        ($desk ?? $this->keep($place, $stay))->setNoShowAt($this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * @throws DeskRefusedException
     */
    public function undoNoShow(StayInterface $stay): void
    {
        $desk = $this->desk->findOneByStay($stay);
        if (null === $desk || null === $desk->getNoShowAt()) {
            throw new DeskRefusedException('stay', \sprintf('%s is not a no-show.', $stay->getReference()));
        }
        $desk->setNoShowAt(null);
        $this->entityManager->flush();
    }

    /**
     * The units a stay is given, read from what was typed: as many of each
     * kind as it takes, each a unit the place has, none held by a guest in
     * house.
     *
     * @param array<string, string> $typed
     *
     * @return list<array{type: string, name: string, number: int}>
     *
     * @throws DeskRefusedException
     */
    private function given(PlaceInterface $place, StayInterface $stay, array $typed): array
    {
        $has = [];
        foreach ($this->units($place) ?? [] as $kind) {
            $has[$kind['type']] = $kind;
        }
        $held = [];
        foreach ($this->desk->findInHouseAt($place) as $other) {
            foreach ($other->getUnits() as $unit) {
                $held[$unit['type'].'#'.$unit['number']] = $other->getReference();
            }
        }

        $given = [];
        foreach ($stay->getUnits() as $wanted) {
            $field = 'units['.$wanted['type'].']';
            $name = $wanted['name'];
            $parts = preg_split('/[\s,]+/', trim($typed[$wanted['type']] ?? ''), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                if (!ctype_digit($part)) {
                    throw new DeskRefusedException($field, \sprintf('Give %s numbers: 3, 4.', $name));
                }
            }
            $numbers = array_values(array_unique(array_map('intval', $parts)));
            if (\count($numbers) !== $wanted['count']) {
                throw new DeskRefusedException($field, \sprintf('%s takes %d %s: give %d %s.', $stay->getReference(), $wanted['count'], $name, $wanted['count'], 1 === $wanted['count'] ? 'number' : 'numbers'));
            }
            $most = $has[$wanted['type']]['count'] ?? 0;
            foreach ($numbers as $number) {
                if ($number < 1 || $number > $most) {
                    throw new DeskRefusedException($field, \sprintf('%s %d: %s has %s 1 to %d.', $name, $number, $place->getName(), $name, $most));
                }
                if (isset($held[$wanted['type'].'#'.$number])) {
                    throw new DeskRefusedException($field, \sprintf('%s %d is %s\'s, in house.', $name, $number, $held[$wanted['type'].'#'.$number]));
                }
                $given[] = ['type' => $wanted['type'], 'name' => $name, 'number' => $number];
            }
        }

        return $given;
    }

    private function keep(PlaceInterface $place, StayInterface $stay): DeskStay
    {
        $desk = new DeskStay($stay->getStayKind(), $stay->getStayId(), $place->getPlaceKind(), $place->getPlaceId(), $stay->getReference());
        $this->entityManager->persist($desk);

        return $desk;
    }

    /**
     * @return list<StayInterface>
     */
    private function staysAt(PlaceInterface $place, \DateTimeImmutable $first, \DateTimeImmutable $last): array
    {
        $found = [];
        foreach ($this->stays as $source) {
            foreach ($source->at($place, $first, $last) as $stay) {
                $found[] = $stay;
            }
        }

        return $found;
    }

    /**
     * @return list<array{type: string, name: string, count: int}>|null
     */
    private function units(PlaceInterface $place): ?array
    {
        foreach ($this->unitSources as $source) {
            $units = $source->units($place);
            if (null !== $units) {
                return $units;
            }
        }

        return null;
    }
}
