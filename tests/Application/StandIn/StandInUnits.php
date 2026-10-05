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
use Vivutio\Contracts\Stay\UnitSourceInterface;

/**
 * A package's units, played by invented ones: the lodge has five tents.
 */
final class StandInUnits implements UnitSourceInterface
{
    public function units(PlaceInterface $place): ?array
    {
        return StandInPlaces::KIND === $place->getPlaceKind() ? [['type' => StandInStays::TENT, 'name' => 'Tent', 'count' => 5]] : null;
    }
}
