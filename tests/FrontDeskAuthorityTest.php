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

namespace Vivutio\FrontDesk\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Vivutio\Bundle\IdentityBundle\Test\AuthorityTestCase;
use Vivutio\Bundle\IdentityBundle\Test\Probe;
use Vivutio\FrontDesk\Controller\FrontDeskController;
use Vivutio\FrontDesk\Entity\DeskStay;
use Vivutio\FrontDesk\Tests\Application\Kernel;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInPlaces;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInStay;
use Vivutio\FrontDesk\Tests\Application\StandIn\StandInStays;

/**
 * The module held to the core's five proofs, through the base every module's
 * suite extends. Record a reviewed change to the table with:
 *
 *     VIVUTIO_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit --filter FrontDeskAuthorityTest
 */
final class FrontDeskAuthorityTest extends AuthorityTestCase
{
    private const string BOARD = '/front-desk/lodge/'.StandInPlaces::LODGE;
    private const string ARRIVING = self::BOARD.'/stays/lodge_booking/0199b1c0-0000-7000-8000-0000000a0101';
    private const string LEAVING = self::BOARD.'/stays/lodge_booking/0199b1c0-0000-7000-8000-0000000a0102';
    private const string IN_HOUSE = self::BOARD.'/stays/lodge_booking/0199b1c0-0000-7000-8000-0000000a0103';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function probes(): array
    {
        return [
            new Probe(FrontDeskController::PLACES, 'GET', '/front-desk'),
            new Probe(FrontDeskController::BOARD, 'GET', self::BOARD),
            new Probe(FrontDeskController::NO_SHOW, 'POST', self::LEAVING.'/no-show', formAt: self::BOARD),
            new Probe(FrontDeskController::UNDO_NO_SHOW, 'POST', self::LEAVING.'/undo-no-show', formAt: self::BOARD),
            new Probe(FrontDeskController::CHECK_IN, 'POST', self::ARRIVING.'/check-in', ['units' => ['tent' => '1']], formAt: self::BOARD),
            new Probe(FrontDeskController::CHECK_OUT, 'POST', self::IN_HOUSE.'/check-out', formAt: self::BOARD),
        ];
    }

    protected static function packageDirectory(): string
    {
        return \dirname(__DIR__);
    }

    protected static function authorityTable(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected function seedSubjects(EntityManagerInterface $entityManager): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        StandInStays::$stays = [
            new StandInStay('0199b1c0-0000-7000-8000-0000000a0101', 'PR-0001', 'Probed party', $today, 2, 1),
            new StandInStay('0199b1c0-0000-7000-8000-0000000a0102', 'PR-0002', 'Probed couple', $today, 1, 1),
            new StandInStay('0199b1c0-0000-7000-8000-0000000a0103', 'PR-0003', 'Probed family', $today, 2, 1),
        ];
        $entityManager->persist((new DeskStay(StandInStays::KIND, '0199b1c0-0000-7000-8000-0000000a0103', StandInPlaces::KIND, StandInPlaces::LODGE, 'PR-0003'))
            ->setCheckedInAt(new \DateTimeImmutable())
            ->setUnits([['type' => StandInStays::TENT, 'name' => 'Tent', 'number' => 5]]));
        $entityManager->flush();
    }
}
