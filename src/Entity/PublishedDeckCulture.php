<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How many draw deck cards a decklist has of a culture, for browsing filters
 * (phase3/03). Rebuilt whenever the decklist's slots are set.
 */
#[ORM\Entity]
#[ORM\Table(name: 'published_deck_culture')]
#[ORM\Index(name: 'published_deck_culture_culture_idx', columns: ['culture_id', 'published_deck_id'])]
class PublishedDeckCulture
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: PublishedDeck::class, inversedBy: 'cultures')]
    #[ORM\JoinColumn(name: 'published_deck_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected PublishedDeck $decklist;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Culture::class)]
    #[ORM\JoinColumn(name: 'culture_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Culture $culture;

    #[ORM\Column(name: 'card_count', type: 'smallint', nullable: false)]
    protected int $count = 0;

    public function __construct(PublishedDeck $decklist, Culture $culture, int $count)
    {
        $this->decklist = $decklist;
        $this->culture = $culture;
        $this->count = $count;
    }

    public function getDecklist(): PublishedDeck { return $this->decklist; }
    public function getCulture(): Culture { return $this->culture; }
    public function getCount(): int { return $this->count; }
    public function setCount(int $count): void { $this->count = $count; }
}
