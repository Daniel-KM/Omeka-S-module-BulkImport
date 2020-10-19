<?php declare(strict_types=1);
namespace BulkImport\Traits;

use Laminas\ServiceManager\ServiceLocatorInterface;

trait ServiceLocatorAwareTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * Get the service locator.
     *
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->services;
    }

    /**
     * Set the service locator.
     *
     * @param ServiceLocatorInterface $services
     * @return self
     */
    public function setServiceLocator(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        return $this;
    }
}
