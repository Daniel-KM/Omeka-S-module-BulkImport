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
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var Importer
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
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $comment;

    /**
     * @var Job
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
     * @var array
     * @Column(
     *     type="json",
     *     nullable=true
     * )
     */
    protected $readerParams;

    /**
     * @var array
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

    /**
     * @param Importer $importer
     * @return self
     */
    public function setImporter(Importer $importer)
    {
        $this->importer = $importer;
        return $this;
    }

    /**
     * @return \BulkImport\Entity\Importer
     */
    public function getImporter()
    {
        return $this->importer;
    }

    /**
     * @param string $comment
     * @return self
     */
    public function setComment($comment)
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * @return string
     */
    public function getComment()
    {
        return $this->comment;
    }

    /**
     * @param Job $job
     * @return self
     */
    public function setJob(Job $job)
    {
        $this->job = $job;
        return $this;
    }

    /**
     * @return \Omeka\Entity\Job
     */
    public function getJob()
    {
        return $this->job;
    }

    /**
     * @param array|\Traversable $readerParams
     * @return self
     */
    public function setReaderParams($readerParams)
    {
        $this->readerParams = $readerParams;
        return $this;
    }

    /**
     * @return array
     */
    public function getReaderParams()
    {
        return $this->readerParams;
    }

    /**
     * @param array|\Traversable $processorParams
     * @return self
     */
    public function setProcessorParams($processorParams)
    {
        $this->processorParams = $processorParams;
        return $this;
    }

    /**
     * @return array
     */
    public function getProcessorParams()
    {
        return $this->processorParams;
    }
}
