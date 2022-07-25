<?php declare(strict_types=1);

namespace BulkImport\Api\Adapter;

use BulkImport\Api\Representation\ImportedRepresentation;
use BulkImport\Entity\Imported;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ImportedAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'job_id' => 'job',
        'entity_id' => 'entityId',
        'entity_name' => 'entityName',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'job' => 'job',
        'entity_id' => 'entityId',
        'entity_name' => 'entityName',
    ];

    public function getResourceName()
    {
        return 'bulk_importeds';
    }

    public function getEntityClass()
    {
        return Imported::class;
    }

    public function getRepresentationClass()
    {
        return ImportedRepresentation::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['job_id'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.job',
                $this->createNamedParameter($qb, $query['job_id'])
            ));
        }
        if (isset($query['entity_id'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.entityId',
                $this->createNamedParameter($qb, $query['entity_id'])
            ));
        }

        if (isset($query['entity_name'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.entityName',
                $this->createNamedParameter($qb, $query['entity_name'])
            ));
        }
    }

    public function hydrate(
        Request $request,
        EntityInterface $entity,
        ErrorStore $errorStore
    ): void {
        /** @var \BulkImport\Entity\Imported $entity */
        $data = $request->getContent();
        if (isset($data['o:job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o:job']['o:id']);
            $entity->setJob($job);
        }

        if (isset($data['entity_id'])) {
            $entity->setEntityId($data['entity_id']);
        }

        if (isset($data['entity_name'])) {
            $entity->setEntityName($data['entity_name']);
        }
    }
}
