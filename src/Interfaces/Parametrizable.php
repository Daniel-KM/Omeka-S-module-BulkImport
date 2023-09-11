<?php declare(strict_types=1);

namespace BulkImport\Interfaces;

use Laminas\Form\Form;

interface Parametrizable
{
    public function setParams(array $params): self;

    public function getParams(): array;

    /**
     * @param string $name
     * @return mixed
     */
    public function getParam(string $name, $default = null);

    public function getParamsFormClass(): string;

    public function handleParamsForm(Form $form): self;
}
