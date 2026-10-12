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
use Vivutio\Contracts\Place\PlaceSourceInterface;

/**
 * A package's places, played by an invented lodge and an invented camp.
 */
final class StandInPlaces implements PlaceSourceInterface
{
    public const string KIND = 'lodge';
    public const string LODGE = '0199b1c0-0000-7000-8000-00000000d001';
    public const string CAMP = '0199b1c0-0000-7000-8000-00000000d002';

    private const array NAMES = [self::LODGE => 'Vivutio Stand-in Lodge', self::CAMP => 'Vivutio Stand-in Camp'];

    public static function lodge(): PlaceInterface
    {
        return self::place(self::LODGE);
    }

    public static function camp(): PlaceInterface
    {
        return self::place(self::CAMP);
    }

    private static function place(string $id): PlaceInterface
    {
        return new readonly class($id, self::NAMES[$id]) implements PlaceInterface {
            public function __construct(
                private string $id,
                private string $name,
            ) {
            }

            public function getPlaceKind(): string
            {
                return StandInPlaces::KIND;
            }

            public function getPlaceId(): string
            {
                return $this->id;
            }

            public function getName(): string
            {
                return $this->name;
            }
        };
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Lodges';
    }

    public function places(): iterable
    {
        yield self::lodge();
        yield self::camp();
    }

    public function find(string $id): ?PlaceInterface
    {
        return isset(self::NAMES[$id]) ? self::place($id) : null;
    }
}
