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
     * @var array
     *
     * @Column(
     *     type="json",
     *     nullable=true
     * )
     */
    protected $readerParams;

    /**
     * @var array
     *
     * @Column(
     *     type="json",
     *     nullable=true
     * )
     */
    protected $processorParams;

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

    public function setComment($comment): self
    {
        $this->comment = (string) $comment ?: null;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
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

    public function setReaderParams($readerParams): self
    {
        $this->readerParams = $readerParams;
        return $this;
    }

    public function getReaderParams(): ?array
    {
        return $this->readerParams;
    }

    public function setProcessorParams(array $processorParams): self
    {
        $this->processorParams = $processorParams;
        return $this;
    }

    public function getProcessorParams(): ?array
    {
        return $this->processorParams;
    }
}
