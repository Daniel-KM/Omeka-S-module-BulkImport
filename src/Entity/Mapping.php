<?php declare(strict_types=1);

namespace BulkImport\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\User;

/**
 * @Entity
 * @Table(
 *     name="bulk_mapping"
 * )
 *
 * @todo The mapping can be ini/xml/json or native or multi-column.
 */
class Mapping extends AbstractEntity
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
     *     unique=true,
     *     nullable=false,
     *     length=190
     * )
     */
    protected $label = '';

    /**
     * @Column(
     *     type="text",
     *     nullable=false
     * )
     */
    protected $mapping = '';

    /**
     * @var DateTime
     * @Column(
     *     type="datetime"
     * )
     */
    protected $created;

    /**
     * @var DateTime
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $modified;

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
        $this->label = (string) $label;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setMapping(?string $mapping): self
    {
        $this->mapping = (string) $mapping;
        return $this;
    }

    public function getMapping(): string
    {
        return $this->mapping;
    }

    public function setCreated(DateTime $dateTime): self
    {
        $this->created = $dateTime;
        return $this;
    }

    public function getCreated(): DateTime
    {
        return $this->created;
    }

    public function setModified(?DateTime $dateTime): self
    {
        $this->modified = $dateTime;
        return $this;
    }

    public function getModified(): ?DateTime
    {
        return $this->modified;
    }
}
