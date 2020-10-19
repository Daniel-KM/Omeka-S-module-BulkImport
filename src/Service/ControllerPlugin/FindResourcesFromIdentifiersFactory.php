<?php declare(strict_types=1);
namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class FindResourcesFromIdentifiersFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        return new FindResourcesFromIdentifiers(
            $services->get('Omeka\Connection'),
            $services->get('ControllerPluginManager')->get('api'),
            $this->supportAnyValue($services)
        );
    }

    protected function supportAnyValue(ContainerInterface $services)
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // To do a request is the simpler way to check if the flag ONLY_FULL_GROUP_BY
        // is set in any databases, systems and versions and that it can be
        // bypassed by Any_value().
        $sql = 'SELECT ANY_VALUE(id) FROM user LIMIT 1;';
        try {
            $connection->query($sql)->fetchColumn();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
