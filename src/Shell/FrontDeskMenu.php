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

use Vivutio\Contracts\Shell\MenuEntry;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\FrontDesk\Controller\FrontDeskController;

/**
 * The desk in the menu, with lucide's concierge bell.
 */
final readonly class FrontDeskMenu implements MenuSourceInterface
{
    public function entries(): iterable
    {
        yield new MenuEntry(
            FrontDeskController::PLACES,
            'Front desk',
            FrontDeskController::READ,
            '<path d="M3 20a1 1 0 0 1-1-1v-1a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v1a1 1 0 0 1-1 1Z"/><path d="M20 16a8 8 0 1 0-16 0"/><path d="M12 4v4"/><path d="M10 4h4"/>',
            'front_desk',
        );
    }
}
