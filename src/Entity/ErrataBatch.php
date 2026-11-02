<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A batch of errata released together, by Decipher or the Players Council
 * (decision 0021). Code is lotrtcg-card-data's batch ID, e.g. pc-2024-12-06.
 */
#[ORM\Entity]
#[ORM\Table(name: 'errata_batch')]
#[ORM\UniqueConstraint(name: 'errata_batch_code_idx', columns: ['code'])]
class ErrataBatch
{
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    protected $id;

    #[ORM\Column(name: 'code', type: 'string', length: 40, nullable: false)]
    protected string $code;

    #[ORM\Column(name: 'issuer', type: 'string', length: 50, nullable: false)]
    protected string $issuer;

    #[ORM\Column(name: 'release_date', type: 'date', nullable: false)]
    protected DateTimeInterface $releaseDate;

    #[ORM\Column(name: 'name', type: 'string', length: 100, nullable: true)]
    protected ?string $name = null;

    public function getId() { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = $code; }
    public function getIssuer(): string { return $this->issuer; }
    public function setIssuer(string $issuer): void { $this->issuer = $issuer; }
    public function getReleaseDate(): DateTimeInterface { return $this->releaseDate; }
    public function setReleaseDate(DateTimeInterface $releaseDate): void { $this->releaseDate = $releaseDate; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): void { $this->name = $name; }

    /** The batch's name, or its issuer and date when it has none */
    public function getLabel(): string
    {
        return $this->name ?? ($this->issuer . ' ' . $this->releaseDate->format('Y-m-d'));
    }
}
