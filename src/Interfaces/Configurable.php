<?php declare(strict_types=1);

namespace BulkImport\Interfaces;

use Laminas\Form\Form;

interface Configurable
{
    public function setConfig(array $config): self;

    public function getConfig(): array;

    /**
     * @param string $name
     * @return mixed
     */
    public function getConfigParam($name, $default = null);

    public function getConfigFormClass(): string;

    public function handleConfigForm(Form $form): self;
}
