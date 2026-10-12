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

namespace Vivutio\FrontDesk\Tests\Application\StandIn;

use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Stay\StayInterface;

/**
 * A stay a package offers, played by an invented one.
 */
final readonly class StandInStay implements StayInterface
{
    public function __construct(
        private string $id,
        private string $reference,
        private string $guest,
        private string $arrival,
        private int $nights,
        private int $tents,
        private bool $expected = true,
        private string $place = StandInPlaces::LODGE,
    ) {
    }

    public function placedAt(): PlaceInterface
    {
        return StandInPlaces::CAMP === $this->place ? StandInPlaces::camp() : StandInPlaces::lodge();
    }

    public function getStayKind(): string
    {
        return StandInStays::KIND;
    }

    public function getStayId(): string
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getGuest(): string
    {
        return $this->guest;
    }

    public function getArrival(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->arrival);
    }

    public function getDeparture(): \DateTimeImmutable
    {
        return $this->getArrival()->modify(\sprintf('+%d days', $this->nights));
    }

    public function getUnits(): array
    {
        return [['type' => StandInStays::TENT, 'name' => 'Tent', 'count' => $this->tents]];
    }

    public function isExpected(): bool
    {
        return $this->expected;
    }
}
