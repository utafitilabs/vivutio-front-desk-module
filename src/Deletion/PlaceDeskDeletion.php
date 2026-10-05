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

namespace Vivutio\FrontDesk\Deletion;

use Doctrine\ORM\EntityManagerInterface;
use Vivutio\Contracts\Deletion\DeletionContributorInterface;
use Vivutio\Contracts\Deletion\DeletionLine;
use Vivutio\Contracts\Deletion\DeletionSubject;
use Vivutio\Contracts\Place\PlaceInterface;
use Vivutio\FrontDesk\Entity\DeskStay;

/**
 * When a Super Admin deletes a place, whichever package's, the desk's records
 * there go with it: who checked in and out, the units given, the no-shows.
 * The desk owns no place, so it describes nothing and clears its rows first.
 */
final readonly class PlaceDeskDeletion implements DeletionContributorInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function supports(object $record): bool
    {
        return $record instanceof PlaceInterface;
    }

    public function describe(object $record): ?DeletionSubject
    {
        return null;
    }

    public function whatGoes(object $record): array
    {
        $count = \count($this->at($record));

        return 0 === $count ? [] : [new DeletionLine($count, 'front desk record', 'front desk records')];
    }

    public function whatStays(object $record): array
    {
        return [];
    }

    public function delete(object $record): void
    {
        foreach ($this->at($record) as $stay) {
            $this->entityManager->remove($stay);
        }
    }

    /**
     * @return list<DeskStay>
     */
    private function at(object $place): array
    {
        \assert($place instanceof PlaceInterface);

        return $this->entityManager->getRepository(DeskStay::class)->findBy(['placeKind' => $place->getPlaceKind(), 'placeId' => $place->getPlaceId()]);
    }
}
