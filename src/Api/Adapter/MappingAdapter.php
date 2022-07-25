<?php declare(strict_types=1);

namespace BulkImport\Api\Adapter;

use BulkImport\Api\Representation\MappingRepresentation;
use BulkImport\Entity\Mapping;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class MappingAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'label' => 'label',
        'owner_id' => 'owner',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'label' => 'label',
        'owner' => 'owner',
        'mapping' => 'mapping',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'bulk_mappings';
    }

    public function getRepresentationClass()
    {
        return MappingRepresentation::class;
    }

    public function getEntityClass()
    {
        return Mapping::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['label'])) {
            $qb->andWhere($expr->eq(
                "omeka_root.label",
                $this->createNamedParameter($qb, $query['label'])
            ));
        }

        if (isset($query['owner_id']) && is_numeric($query['owner_id'])) {
            $userAlias = $this->createAlias();
            $qb
                ->innerJoin('omeka_root.owner', $userAlias)
                ->andWhere($expr->eq(
                    $userAlias . '.id',
                    $this->createNamedParameter($qb, $query['owner_id'])
                ));
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        $inflector = InflectorFactory::create()->build();
        foreach ($data as $key => $value) {
            $posColon = strpos($key, ':');
            $keyName = $posColon === false ? $key : substr($key, $posColon + 1);
            $method = 'set' . ucfirst($inflector->camelize($keyName));
            if (!method_exists($entity, $method)) {
                continue;
            }
            $entity->$method($value);
        }

        $this->hydrateOwner($request, $entity);
        $this->updateTimestamps($request, $entity);
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore): void
    {
        $label = $entity->getLabel();
        if (false == trim($label)) {
            $errorStore->addError('o:label', 'The label cannot be empty.'); // @translate
        }
        if (!$this->isUnique($entity, ['label' => $label])) {
            $errorStore->addError('o:label', 'The label is already taken.'); // @translate
        }
    }
}
