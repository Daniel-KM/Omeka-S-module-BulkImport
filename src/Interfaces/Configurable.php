<?php declare(strict_types=1);
namespace BulkImport\Interfaces;

use Laminas\Form\Form;

interface Configurable
{
    /**
     * @param array $config
     * @return self
     */
    public function setConfig($config);

    /**
     * @return array
     */
    public function getConfig();

    /**
     * @return string
     */
    public function getConfigFormClass();

    /**
     * @param Form $form
     */
    public function handleConfigForm(Form $form);
}
