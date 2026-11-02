<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How many draw deck cards a decklist has of a culture, for browsing filters
 * (phase3/03). Rebuilt whenever the decklist's slots are set.
 */
#[ORM\Entity]
#[ORM\Table(name: 'decklist_culture')]
#[ORM\Index(name: 'decklist_culture_culture_idx', columns: ['culture_id', 'decklist_id'])]
class DecklistCulture
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Decklist::class, inversedBy: 'cultures')]
    #[ORM\JoinColumn(name: 'decklist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Decklist $decklist;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Culture::class)]
    #[ORM\JoinColumn(name: 'culture_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected Culture $culture;

    #[ORM\Column(name: 'card_count', type: 'smallint', nullable: false)]
    protected int $count = 0;

    public function __construct(Decklist $decklist, Culture $culture, int $count)
    {
        $this->decklist = $decklist;
        $this->culture = $culture;
        $this->count = $count;
    }

    public function getDecklist(): Decklist { return $this->decklist; }
    public function getCulture(): Culture { return $this->culture; }
    public function getCount(): int { return $this->count; }
    public function setCount(int $count): void { $this->count = $count; }
}
