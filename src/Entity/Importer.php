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
     * @var int
     *
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $label;

    /**
     * @var array
     *
     * @Column(
     *      type="json",
     *      nullable=false
     * )
     */
    protected $config;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $readerClass;

    /**
     * @var array
     *
     * @Column(
     *     type="json",
     *     nullable=true
     * )
     */
    protected $readerConfig;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $processorClass;

    /**
     * @var array
     *
     * @Column(
     *      type="json",
     *      nullable=true
     * )
     */
    protected $processorConfig;

    /**
     * @var User
     *
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
     * @var Import[]|ArrayCollection
     *
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

    public function setLabel(?string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setReaderClass(?string $readerClass): self
    {
        $this->readerClass = $readerClass;
        return $this;
    }

    public function getReaderClass(): ?string
    {
        return $this->readerClass;
    }

    public function setReaderConfig(?array $readerConfig): self
    {
        $this->readerConfig = $readerConfig;
        return $this;
    }

    public function getReaderConfig(): ?array
    {
        return $this->readerConfig;
    }

    public function setProcessorClass(?string $processorClass): self
    {
        $this->processorClass = $processorClass;
        return $this;
    }

    public function getProcessorClass(): string
    {
        return $this->processorClass;
    }

    public function setProcessorConfig(?array $processorConfig): self
    {
        $this->processorConfig = $processorConfig;
        return $this;
    }

    public function getProcessorConfig(): ?array
    {
        return $this->processorConfig;
    }

    public function setOwner(?User $owner = null): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    /**
     * @return Import[]|ArrayCollection
     */
    public function getImports(): ArrayCollection
    {
        return $this->imports;
    }
}
