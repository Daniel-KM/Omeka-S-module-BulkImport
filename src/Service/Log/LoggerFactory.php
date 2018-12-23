<?php
namespace BulkImport\Service\Log;

use BulkImport\Log\Writer;
use Interop\Container\ContainerInterface;
use Zend\Log\Filter\Priority;
use Zend\ServiceManager\Factory\FactoryInterface;

/**
 * Logger factory.
 */
class LoggerFactory implements FactoryInterface
{
    /**
     * Create the logger service.
     */
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        // $config = $serviceLocator->get('Config');

        $logger = new $requestedName();

        $writer = new Writer($serviceLocator);
        $logger->addWriter($writer);

        // $filter = new Priority($config['logger']['priority']7);
        $filter = new Priority(\Zend\Log\Logger::DEBUG);
        $writer->addFilter($filter);

        return $logger;
    }
}
