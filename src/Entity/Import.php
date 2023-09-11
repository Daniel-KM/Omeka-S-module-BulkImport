<?php declare(strict_types=1);

namespace BulkImport\Entity;

use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Job;

/**
 * @Entity
 * @Table(
 *     name="bulk_import"
 * )
 */
class Import extends AbstractEntity
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
     * @var Importer
     *
     * @ManyToOne(
     *     targetEntity=Importer::class,
     *     inversedBy="imports",
     *     fetch="EXTRA_LAZY"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="SET NULL"
     * )
     */
    protected $importer;

    /**
     * @var Job
     *
     * @OneToOne(
     *     targetEntity=\Omeka\Entity\Job::class
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="SET NULL"
     * )
     */
    protected $job;

    /**
     * @var Job
     *
     * @OneToOne(
     *     targetEntity=\Omeka\Entity\Job::class
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="SET NULL"
     * )
     */
    protected $undoJob;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $comment;

    /**
     * @var array
     *
     * @Column(
     *     type="json",
     *     nullable=false
     * )
     */
    protected $params;

    public function getId()
    {
        return $this->id;
    }

    public function setImporter(Importer $importer): self
    {
        $this->importer = $importer;
        return $this;
    }

    public function getImporter(): ?Importer
    {
        return $this->importer;
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

    public function setUndoJob(?Job $undoJob): self
    {
        $this->undoJob = $undoJob;
        return $this;
    }

    public function getUndoJob(): ?Job
    {
        return $this->undoJob;
    }

    public function setComment($comment): self
    {
        $this->comment = (string) $comment ?: null;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    public function getParams(): array
    {
        return $this->params;
    }
}
