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
use Vivutio\Contracts\Stay\StaySourceInterface;

/**
 * A package's stays, played by invented ones: what a specification names.
 */
final class StandInStays implements StaySourceInterface
{
    public const string KIND = 'lodge_booking';
    public const string TENT = 'tent';

    /** @var list<StandInStay> */
    public static array $stays = [];

    public function kind(): string
    {
        return self::KIND;
    }

    public function at(PlaceInterface $place, \DateTimeImmutable $first, \DateTimeImmutable $last): iterable
    {
        if (StandInPlaces::KIND !== $place->getPlaceKind()) {
            return [];
        }

        return array_values(array_filter(self::$stays, static fn (StandInStay $stay): bool => $stay->placedAt()->getPlaceId() === $place->getPlaceId() && $stay->getArrival() <= $last && $stay->getDeparture() >= $first));
    }

    public function find(string $id): ?StayInterface
    {
        foreach (self::$stays as $stay) {
            if ($stay->getStayId() === $id) {
                return $stay;
            }
        }

        return null;
    }
}
