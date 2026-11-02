<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A LOTR culture (Dwarven, Elven, Sauron...). Created by app:import:cards from the
 * card data; code is the name in lower case with _ for anything else (uruk_hai).
 */
#[ORM\Entity]
#[ORM\Table(name: 'culture')]
#[ORM\UniqueConstraint(name: 'culture_code_idx', columns: ['code'])]
class Culture
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    #[ORM\Column(name: 'code', type: 'string', length: 30, nullable: false)]
    protected string $code;

    #[ORM\Column(name: 'name', type: 'string', length: 50, nullable: false)]
    protected string $name;

    /** free_peoples or shadow: the side most of the culture's cards are on */
    #[ORM\Column(name: 'side', type: 'string', length: 20, nullable: true)]
    protected ?string $side = null;

    #[ORM\OneToMany(targetEntity: Card::class, mappedBy: 'culture')]
    protected Collection $cards;

    public function __construct()
    {
        $this->cards = new ArrayCollection();
    }

    public function getId() { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = $code; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getSide(): ?string { return $this->side; }
    public function setSide(?string $side): void { $this->side = $side; }
    public function getCards(): Collection { return $this->cards; }

    public function __toString(): string
    {
        return $this->name;
    }
}
