<?php declare(strict_types=1);

namespace BulkImport\Interfaces;

use Laminas\Form\Form;

interface Configurable
{
    /**
     * @param array $config
     * @return self
     */
    public function setConfig(array $config): self;

    /**
     * @return array
     */
    public function getConfig(): array;

    /**
     * @return string
     */
    public function getConfigFormClass(): string;

    /**
     * @param Form $form
     * @return self
     */
    public function handleConfigForm(Form $form): self;
}
