<?php
namespace BulkImport\Interfaces;

use Zend\Form\Form;

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
