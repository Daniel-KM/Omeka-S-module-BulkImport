<?php declare(strict_types=1);

namespace BulkImport\Interfaces;

use Laminas\Form\Form;

interface Parametrizable
{
    /**
     * @param array $params
     * @return self
     */
    public function setParams(array $params): self;

    /**
     * @return array
     */
    public function getParams(): array;

    /**
     * @return string
     */
    public function getParamsFormClass(): string;

    /**
     * @param Form $form
     */
    public function handleParamsForm(Form $form): self;
}
