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

namespace Vivutio\FrontDesk\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\Contracts\Stay\StayInterface;
use Vivutio\FrontDesk\Entity\DeskStay;

/**
 * @extends ServiceEntityRepository<DeskStay>
 */
class DeskStayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeskStay::class);
    }

    public function findOneByStay(StayInterface $stay): ?DeskStay
    {
        return $this->findOneBy(['stayKind' => $stay->getStayKind(), 'stayId' => $stay->getStayId()]);
    }

    /**
     * Every stay checked in at a place and not yet out.
     *
     * @return list<DeskStay>
     */
    public function findInHouseAt(PlaceInterface $place): array
    {
        return array_values(array_filter(
            $this->findBy(['placeKind' => $place->getPlaceKind(), 'placeId' => $place->getPlaceId()]),
            static fn (DeskStay $stay): bool => $stay->isInHouse(),
        ));
    }

    /**
     * Every stay the desk keeps at a place.
     *
     * @return list<DeskStay>
     */
    public function findAt(PlaceInterface $place): array
    {
        return $this->findBy(['placeKind' => $place->getPlaceKind(), 'placeId' => $place->getPlaceId()]);
    }
}
