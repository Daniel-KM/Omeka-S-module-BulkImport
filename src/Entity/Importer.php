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
     * @var string
     *
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $reader;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $mapper;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     nullable=true,
     *     length=190
     * )
     */
    protected $processor;

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

    public function setOwner(?User $owner = null): self
    {
        $this->owner = $owner;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
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

    public function setReader(?string $reader): self
    {
        $this->reader = $reader;
        return $this;
    }

    public function getReader(): ?string
    {
        return $this->reader;
    }

    public function setMapper(?string $mapper): self
    {
        $this->mapper = $mapper;
        return $this;
    }

    public function getMapper(): ?string
    {
        return $this->mapper;
    }

    public function setProcessor(?string $processor): self
    {
        $this->processor = $processor;
        return $this;
    }

    public function getProcessor(): string
    {
        return $this->processor;
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

    /**
     * @return Import[]|ArrayCollection
     */
    public function getImports(): ArrayCollection
    {
        return $this->imports;
    }
}
