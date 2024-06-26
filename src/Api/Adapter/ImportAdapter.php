<?php declare(strict_types=1);

namespace BulkImport\Api\Adapter;

use BulkImport\Api\Representation\ImportRepresentation;
use BulkImport\Entity\Import;
use Doctrine\Inflector\InflectorFactory;
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
        'job_id' => 'job',
        'undo_job_id' => 'undoJob',
        'comment' => 'comment',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'importer' => 'importer',
        'job' => 'job',
        'undo_job' => 'undoJob',
        'comment' => 'comment',
        'params' => 'params',
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
            $qb->andWhere($expr->eq(
                'omeka_root.importer',
                $this->createNamedParameter($qb, $query['importer_id'])
            ));
        }

        if (isset($query['job_id'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.job',
                $this->createNamedParameter($qb, $query['job_id'])
            ));
        }

        if (isset($query['undo_job_id'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.undoJob',
                $this->createNamedParameter($qb, $query['undo_job_id'])
            ));
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        $inflector = InflectorFactory::create()->build();
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        foreach ($data as $key => $value) {
            $posColon = strpos($key, ':');
            $keyName = $posColon === false ? $key : substr($key, $posColon + 1);
            // Refresh values.
            if (in_array($keyName, ['importer', 'job', 'undo_job'])) {
                if (is_object($value) && $value->getId()) {
                    $value = $entityManager->getReference($value->getResourceId(), $value->getId());
                } elseif (is_numeric($value)) {
                    $linkeds = [
                        'importer' => \BulkImport\Entity\Importer::class,
                        'job' => \Omeka\Entity\Job::class,
                        'undo_job' => \Omeka\Entity\Job::class,
                    ];
                    $value = $entityManager->getReference($linkeds[$keyName], (int) $value);
                }
            }
            $method = 'set' . ucfirst($inflector->camelize($keyName));
            if (method_exists($entity, $method)) {
                $entity->$method($value);
            }
        }
    }
}
