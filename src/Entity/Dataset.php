<?php

namespace App\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * A dataset Red Book shows as it was made elsewhere (Ketura, 2026-11-11): the format timeline,
 * for one. Kept whole, as imported, with when it was made and when it came in.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dataset')]
class Dataset
{
    public const FORMAT_TIMELINE = 'format-timeline';

    #[ORM\Id]
    #[ORM\Column(name: 'name', type: 'string', length: 50)]
    protected string $name;

    /** The dataset as it came, JSON */
    #[ORM\Column(name: 'data', type: 'text', length: 16777215)]
    protected string $data;

    /** Its own schema and generation stamp, as it gives them */
    #[ORM\Column(name: 'schema_name', type: 'string', length: 50, nullable: true)]
    protected ?string $schemaName = null;

    #[ORM\Column(name: 'generated_at', type: 'string', length: 40, nullable: true)]
    protected ?string $generatedAt = null;

    #[ORM\Column(name: 'date_import', type: 'datetime')]
    protected DateTimeInterface $dateImport;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->dateImport = new DateTime();
    }

    public function getName(): string { return $this->name; }
    public function getData(): string { return $this->data; }
    public function getSchemaName(): ?string { return $this->schemaName; }
    public function getGeneratedAt(): ?string { return $this->generatedAt; }
    public function getDateImport(): DateTimeInterface { return $this->dateImport; }

    public function replace(string $data, ?string $schema, ?string $generatedAt): void
    {
        $this->data = $data;
        $this->schemaName = $schema;
        $this->generatedAt = $generatedAt;
        $this->dateImport = new DateTime();
    }
}
