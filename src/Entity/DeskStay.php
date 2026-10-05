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

namespace Vivutio\FrontDesk\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Vivutio\Bundle\IdentityBundle\Entity\Trait\TimestampableTrait;
use Vivutio\FrontDesk\Repository\DeskStayRepository;

/**
 * The desk's own record of a stay, kept by the stay's kind and id, never a
 * copy of it: when its guests were checked in and out, or that they never
 * came, and the units they were given.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: DeskStayRepository::class)]
#[ORM\Table(name: 'front_desk_stay')]
#[ORM\UniqueConstraint(columns: ['stay_kind', 'stay_id'])]
#[ORM\HasLifecycleCallbacks]
class DeskStay
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(length: 32)]
    private string $stayKind;

    #[ORM\Column(type: Types::GUID)]
    private string $stayId;

    #[ORM\Column(length: 32)]
    private string $placeKind;

    #[ORM\Column(type: Types::GUID)]
    private string $placeId;

    /** The stay's reference when the desk first kept it, for saying which stay holds a unit. */
    #[ORM\Column(length: 32)]
    private string $reference;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedInAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedOutAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $noShowAt = null;

    /** @var list<array{type: string, name: string, number: int}> the units it was given */
    #[ORM\Column(type: Types::JSON)]
    private array $units = [];

    public function __construct(string $stayKind, string $stayId, string $placeKind, string $placeId, string $reference)
    {
        $this->stayKind = $stayKind;
        $this->stayId = $stayId;
        $this->placeKind = $placeKind;
        $this->placeId = $placeId;
        $this->reference = $reference;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStayKind(): string
    {
        return $this->stayKind;
    }

    public function getStayId(): string
    {
        return $this->stayId;
    }

    public function getPlaceKind(): string
    {
        return $this->placeKind;
    }

    public function getPlaceId(): string
    {
        return $this->placeId;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getCheckedInAt(): ?\DateTimeImmutable
    {
        return $this->checkedInAt;
    }

    public function setCheckedInAt(?\DateTimeImmutable $checkedInAt): static
    {
        $this->checkedInAt = $checkedInAt;

        return $this;
    }

    public function getCheckedOutAt(): ?\DateTimeImmutable
    {
        return $this->checkedOutAt;
    }

    public function setCheckedOutAt(?\DateTimeImmutable $checkedOutAt): static
    {
        $this->checkedOutAt = $checkedOutAt;

        return $this;
    }

    public function getNoShowAt(): ?\DateTimeImmutable
    {
        return $this->noShowAt;
    }

    public function setNoShowAt(?\DateTimeImmutable $noShowAt): static
    {
        $this->noShowAt = $noShowAt;

        return $this;
    }

    /** Checked in and not yet out. */
    public function isInHouse(): bool
    {
        return null !== $this->checkedInAt && null === $this->checkedOutAt;
    }

    /**
     * @return list<array{type: string, name: string, number: int}>
     */
    public function getUnits(): array
    {
        return $this->units;
    }

    /**
     * @param list<array{type: string, name: string, number: int}> $units
     */
    public function setUnits(array $units): static
    {
        $this->units = $units;

        return $this;
    }
}
