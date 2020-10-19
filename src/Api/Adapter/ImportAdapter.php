<?php declare(strict_types=1);
namespace BulkImport\Api\Adapter;

use BulkImport\Api\Representation\ImportRepresentation;
use BulkImport\Entity\Import;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ImportAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'importer_id' => 'importerId',
    ];

    public function getResourceName()
    {
        return 'bulk_imports';
    }

    public function getRepresentationClass()
    {
        return ImportRepresentation::class;
    }

    public function getEntityClass()
    {
        return Import::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['importer_id'])) {
            $qb->andWhere(
                $expr->eq(
                    'omeka_root.importer',
                    $this->createNamedParameter($qb, $query['importer_id'])
                )
            );
        }

        if (isset($query['job_id'])) {
            $qb->andWhere(
                $expr->eq(
                    'omeka_root.job',
                    $this->createNamedParameter($qb, $query['job_id'])
                )
            );
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        foreach ($data as $key => $value) {
            $posColon = strpos($key, ':');
            $keyName = $posColon === false ? $key : substr($key, $posColon + 1);
            $method = 'set' . ucfirst(Inflector::camelize($keyName));
            if (!method_exists($entity, $method)) {
                continue;
            }
            $entity->$method($value);
        }
    }
}
