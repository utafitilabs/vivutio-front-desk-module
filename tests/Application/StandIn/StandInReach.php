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
use Vivutio\Contracts\Place\ReachSourceInterface;

/**
 * A package's say over reach, played by an invented one: the places it keeps
 * out of everybody's reach, and no say over the rest.
 */
final class StandInReach implements ReachSourceInterface
{
    /** @var list<string> */
    public static array $outOfReach = [];

    public function covers(string $person, PlaceInterface $place): ?bool
    {
        return \in_array($place->getPlaceId(), self::$outOfReach, true) ? false : null;
    }
}
