<?php declare(strict_types=1);
namespace BulkImport\Api\Adapter;

use BulkImport\Api\Representation\ImporterRepresentation;
use BulkImport\Entity\Importer;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ImporterAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'label' => 'label',
        'reader_class' => 'readerClass',
        'processor_class' => 'processorClass',
    ];

    public function getResourceName()
    {
        return 'bulk_importers';
    }

    public function getRepresentationClass()
    {
        return ImporterRepresentation::class;
    }

    public function getEntityClass()
    {
        return Importer::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['owner_id']) && is_numeric($query['owner_id'])) {
            $userAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.owner',
                $userAlias
            );
            $qb->andWhere(
                $expr->eq(
                    $userAlias . '.id',
                    $this->createNamedParameter($qb, $query['owner_id'])
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
