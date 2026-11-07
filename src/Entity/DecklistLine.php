<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A published deck's line of versions (Ketura, 2026-11-07): v1, v2, v3 of the same deck
 * are each a decklist of their own (own page, comments and likes), held together here.
 * It used to be inferred from the private deck they were published from, which broke
 * when that deck was deleted or the next version was built in another. Whose line it is
 * comes from its decklists, so merging a historic account needs nothing here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'decklist_line')]
class DecklistLine
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\Column(name: 'date_creation', type: 'datetime')]
    protected DateTimeInterface $dateCreation;

    /** @var Collection<int, PublishedDeck> */
    #[ORM\OneToMany(targetEntity: PublishedDeck::class, mappedBy: 'line')]
    #[ORM\OrderBy(['version' => 'ASC'])]
    protected Collection $decklists;

    public function __construct()
    {
        $this->dateCreation = new DateTime();
        $this->decklists = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateCreation(): DateTimeInterface
    {
        return $this->dateCreation;
    }

    /** @return Collection<int, PublishedDeck> every version, withdrawn ones included */
    public function getDecklists(): Collection
    {
        return $this->decklists;
    }
}
