<?php declare(strict_types=1);
namespace BulkImport\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\User;

/**
 * @Entity
 * @Table(
 *     name="bulk_importer"
 * )
 */
class Importer extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var string
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $label;

    /**
     * @var string
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $readerClass;

    /**
     * @var array
     * @Column(
     *     type="json",
     *     nullable=true
     * )
     */
    protected $readerConfig;

    /**
     * @var string
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $processorClass;

    /**
     * @var array
     * @Column(
     *      type="json",
     *      nullable=true
     * )
     */
    protected $processorConfig;

    /**
     * @var User
     * @ManyToOne(
     *     targetEntity=\Omeka\Entity\User::class
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="SET NULL"
     * )
     */
    protected $owner;

    /**
     * @OneToMany(
     *     targetEntity=Import::class,
     *     mappedBy="importer",
     *     orphanRemoval=true,
     *     cascade={"persist", "remove"},
     *     indexBy="id"
     * )
     */
    protected $imports;

    public function __construct()
    {
        $this->imports = new ArrayCollection;
    }

    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $label
     * @return self
     */
    public function setLabel($label)
    {
        $this->label = $label;
        return $this;
    }

    /**
     * @return string
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @param string $readerClass
     * @return self
     */
    public function setReaderClass($readerClass)
    {
        $this->readerClass = $readerClass;
        return $this;
    }

    /**
     * @return string
     */
    public function getReaderClass()
    {
        return $this->readerClass;
    }

    /**
     * @param array $readerConfig
     * @return self
     */
    public function setReaderConfig($readerConfig)
    {
        $this->readerConfig = $readerConfig;
        return $this;
    }

    /**
     * @return array
     */
    public function getReaderConfig()
    {
        return $this->readerConfig;
    }

    /**
     * @param string $processorClass
     * @return self
     */
    public function setProcessorClass($processorClass)
    {
        $this->processorClass = $processorClass;
        return $this;
    }

    /**
     * @return string
     */
    public function getProcessorClass()
    {
        return $this->processorClass;
    }

    /**
     * @param array $processorConfig
     * @return self
     */
    public function setProcessorConfig($processorConfig)
    {
        $this->processorConfig = $processorConfig;
        return $this;
    }

    /**
     * @return array
     */
    public function getProcessorConfig()
    {
        return $this->processorConfig;
    }

    /**
     * @param User $owner
     * @return self
     */
    public function setOwner(User $owner = null)
    {
        $this->owner = $owner;
        return $this;
    }

    /**
     * @return \Omeka\Entity\User
     */
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * @return Import[]
     */
    public function getImports()
    {
        return $this->imports();
    }
}
