<?php declare(strict_types=1);

namespace BulkImportTest\Mock\Mvc\Controller\Plugin;

use BulkImport\Mvc\Controller\Plugin\BulkFile;
use Laminas\Mvc\Controller\Plugin\PluginInterface;
use Laminas\Stdlib\DispatchableInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * Wrapper for BulkFile plugin that skips URL validation in tests.
 */
class MockBulkFile implements PluginInterface
{
    /**
     * @var BulkFile
     */
    protected $wrapped;

    /**
     * @var DispatchableInterface
     */
    protected $controller;

    public function __construct(BulkFile $wrapped)
    {
        $this->wrapped = $wrapped;
    }

    public function setController(DispatchableInterface $controller)
    {
        $this->controller = $controller;
        $this->wrapped->setController($controller);
        return $this;
    }

    public function getController()
    {
        return $this->controller;
    }

    /**
     * Always return true for URL checks (skip actual HTTP request).
     */
    public function checkUrl($url, ?ErrorStore $messageStore = null): bool
    {
        return true;
    }

    /**
     * Always return true for file checks in tests.
     */
    public function checkFile($filepath, ?ErrorStore $messageStore = null): bool
    {
        return true;
    }

    /**
     * Always return true for file or URL checks in tests.
     */
    public function checkFileOrUrl($fileOrUrl, ?ErrorStore $messageStore = null): bool
    {
        return true;
    }

    /**
     * Delegate all other method calls to the wrapped plugin.
     */
    public function __call($name, $arguments)
    {
        return $this->wrapped->$name(...$arguments);
    }
}
