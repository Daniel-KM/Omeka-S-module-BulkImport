<?php declare(strict_types=1);
namespace BulkImport\Interfaces;

use Laminas\Form\Form;

interface Parametrizable
{
    /**
     * @param array|\Traversable $config
     * @return self
     */
    public function setParams($params);

    /**
     * @return array|\Traversable
     */
    public function getParams();

    /**
     * @return string
     */
    public function getParamsFormClass();

    /**
     * @param Form $form
     */
    public function handleParamsForm(Form $form);
}
