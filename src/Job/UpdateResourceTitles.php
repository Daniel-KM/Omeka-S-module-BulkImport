<?php declare(strict_types=1);

namespace BulkImport\Job;

use Doctrine\ORM\EntityManager;
use Omeka\Entity\Resource;
use Omeka\Job\AbstractJob;

/**
 * @see \BulkCheck\Job\DbResourceTitle
 */
class UpdateResourceTitles extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 100;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $resourceRepository;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // Because this is an indexer that is used in background, another entity
        // manager is used to avoid conflicts with the main entity manager, for
        // example when the job is run in foreground or multiple resources are
        // imported in bulk, so a flush() or a clear() will not be applied on
        // the imported resources but only on the indexed resources.
        $this->entityManager = $this->getNewEntityManager($services->get('Omeka\EntityManager'));
        $this->connection = $this->entityManager->getConnection();
        $this->resourceRepository = $this->entityManager->getRepository(\Omeka\Entity\Resource::class);

        $ids = $this->getArg('resource_ids', []);
        if (!$ids) {
            return;
        }

        // For quick process, get all the title terms of all templates one time.
        $sql = <<<'SQL'
SELECT id, IFNULL(title_property_id, 1) AS "title_property_id" FROM resource_template ORDER BY id;';
SQL;
        $templateTitleTerms = $this->connection->executeQuery($sql)->fetchAllKeyValue();

        // It's possible to do the process with some not so complex sql queries,
        // but it's done manually for now. May be complicate with the title of
        // the linked resources.

        foreach (array_chunk($ids, self::SQL_LIMIT) as $listIds) {
            /** @var \Omeka\Entity\Resource[] $resources */
            $resources = $this->resourceRepository->findBy(['id' => $listIds], ['id' => 'ASC']);
            foreach ($resources as $resource) {
                $template = $resource->getResourceTemplate();
                $titleTermId = $template && isset($templateTitleTerms[$template->getId()])
                    ? (int) $templateTitleTerms[$template->getId()]
                    : 1;

                $existingTitle = $resource->getTitle();
                $realTitle = $this->getValueFromResource($resource, $titleTermId);

                if ($existingTitle === '') {
                    $existingTitle = null;
                }

                // Real title is trimmed too, like in Omeka.
                $realTitle = $realTitle === null || $realTitle === '' || trim($realTitle) === ''
                    ? null
                    : trim($realTitle);

                $different = $existingTitle !== $realTitle;
                if ($different) {
                    $resource->setTitle($realTitle);
                    $this->entityManager->persist($resource);
                }
            }
            $this->entityManager->flush();
            $this->entityManager->clear();
            unset($resources);
        }
    }

    /**
     * Recursively get the first value of a resource for a term.
     */
    protected function getValueFromResource(Resource $resource, int $termId, int $loop = 0): ?string
    {
        if ($loop > 20) {
            return null;
        }

        /** @var \Omeka\Entity\Value[] $values */
        $values = $resource->getValues()->toArray();
        $values = array_filter($values, function (\Omeka\Entity\Value $v) use ($termId) {
            return $v->getProperty()->getId() === $termId;
        });
        if (!count($values)) {
            return null;
        }

        /** @var \Omeka\Entity\Value $value */
        $value = reset($values);
        $val = (string) $value->getValue();
        if ($val !== '') {
            return $val;
        }

        if ($val = $value->getUri()) {
            return $val;
        }

        $valueResource = $value->getValueResource();
        if (!$valueResource) {
            return null;
        }

        return $this->getValueFromResource($valueResource, $termId, ++$loop);
    }

    /**
     * Create a new EntityManager with the same config.
     */
    private function getNewEntityManager(EntityManager $entityManager): EntityManager
    {
        return EntityManager::create(
            $entityManager->getConnection(),
            $entityManager->getConfiguration(),
            $entityManager->getEventManager()
        );
    }
}
