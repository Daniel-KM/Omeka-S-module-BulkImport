<?php declare(strict_types=1);

namespace BulkImport\Entity;

use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Job;

/**
 * @Entity
 * @Table(
 *     name="bulk_imported"
 * )
 *
 * Adapted from CsvImportEntity.
 */
class Imported extends AbstractEntity
{
    /**
     * @var int
     *
     * @Id
     * @Column(
     *     type="integer"
     * )
     * @GeneratedValue
     */
    protected $id;

    /**
     * var Job
     *
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\Job"
     * )
     * @JoinColumn(
     *     nullable=false
     * )
     */
    protected $job;

    /**
     * @var int
     *
     * @Column(
     *     type="integer"
     * )
     */
    protected $entityId;

    /**
     * @var string
     * API resource type (not neccesarily a Resource class)
     *
     * @Column(
     *     type="string"
     * )
     */
    protected $resourceType;

    public function getId()
    {
        return $this->id;
    }

    public function setJob(?Job $job): self
    {
        $this->job = $job;
        return $this;
    }

    public function getJob(): ?Job
    {
        return $this->job;
    }

    public function setEntityId($entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function setResourceType(string $resourceType): self
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }
}
