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

namespace Vivutio\FrontDesk\Model;

use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Stay\StayInterface;
use Vivutio\FrontDesk\Entity\DeskStay;

/**
 * A day at a place's desk: who is expected and not yet in, who is in house,
 * who is due out or overdue, who left that day, and who never came.
 */
final readonly class DeskBoard
{
    /**
     * @param list<array{stay: StayInterface, desk: DeskStay|null}> $arrivals
     * @param list<array{stay: StayInterface, desk: DeskStay|null}> $inHouse
     * @param list<array{stay: StayInterface, desk: DeskStay|null}> $departures
     * @param list<array{stay: StayInterface, desk: DeskStay|null}> $left
     * @param list<array{stay: StayInterface, desk: DeskStay|null}> $noShows
     * @param list<array{type: string, name: string, count: int}>   $units
     */
    public function __construct(
        public PlaceInterface $place,
        public \DateTimeImmutable $day,
        public array $arrivals,
        public array $inHouse,
        public array $departures,
        public array $left,
        public array $noShows,
        public array $units,
    ) {
    }
}
