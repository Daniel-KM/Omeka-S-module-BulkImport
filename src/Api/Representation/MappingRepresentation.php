<?php declare(strict_types=1);

namespace BulkImport\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class MappingRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'mapping';
    }

    public function getJsonLdType()
    {
        return 'o-bulk:Mapping';
    }

    public function getJsonLd()
    {
        $owner = $this->owner();

        $getDateTimeJsonLd = function (?\DateTime $dateTime): ?array {
            return $dateTime
                ? [
                    '@value' => $dateTime->format('c'),
                    '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
                ]
                : null;
        };

        return [
            'o:id' => $this->id(),
            'o:owner' => $owner ? $owner->getReference()->jsonSerialize() : null,
            'o:label' => $this->label(),
            'o-bulk:mapping' => $this->mapping(),
            'o:created' => $getDateTimeJsonLd($this->resource->getCreated()),
            'o:modified' => $getDateTimeJsonLd($this->resource->getModified()),
        ];
    }

    public function owner(): ?\Omeka\Api\Representation\UserRepresentation
    {
        $owner = $this->resource->getOwner();
        return $owner
            ? $this->getAdapter('users')->getRepresentation($owner)
            : null;
    }

    public function label(): string
    {
        return $this->resource->getLabel();
    }

    public function mapping(): string
    {
        return $this->resource->getMapping();
    }

    public function created(): \DateTime
    {
        return $this->resource->getCreated();
    }

    public function modified(): ?\DateTime
    {
        return $this->resource->getModified();
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/bulk/id',
            [
                'controller' => $this->getControllerName(),
                'action' => $action,
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }
}
