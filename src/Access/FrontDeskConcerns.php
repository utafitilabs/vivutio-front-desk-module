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

namespace Vivutio\FrontDesk\Access;

use Vivutio\Contracts\Access\Concern;
use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Access\Scope;
use Vivutio\Contracts\Access\Verb;

/**
 * What a position may grant at the desk: reading a place's board, and
 * recording arrivals, departures and no-shows there.
 */
final readonly class FrontDeskConcerns implements ConcernSourceInterface
{
    public const string FRONT_DESK = 'front_desk';

    public function declaredBy(): string
    {
        return 'Front desk';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::FRONT_DESK,
            label: 'Front desk',
            description: 'A place\'s arrivals, guests in house and departures: reading the board, and checking guests in and out.',
            verbs: [Verb::Read, Verb::Record],
            scopes: [Scope::ORGANIZATION],
            moduleSlug: 'front_desk',
        );
    }
}
