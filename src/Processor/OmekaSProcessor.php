<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\OmekaSProcessorConfigForm;
use BulkImport\Form\Processor\OmekaSProcessorParamsForm;
use Laminas\Form\Form;

class OmekaSProcessor extends AbstractFullProcessor
{
    protected $resourceLabel = 'Omeka S'; // @translate
    protected $configFormClass = OmekaSProcessorConfigForm::class;
    protected $paramsFormClass = OmekaSProcessorParamsForm::class;

    public function handleConfigForm(Form $form): void
    {
        $values = $form->getData();
        $config = new ArrayObject;
        $this->handleFormGeneric($config, $values);
        $defaults = [
            'endpoint' => null,
            'key_identity' => null,
            'key_credential' => null,
        ];
        $config = array_intersect_key($config->getArrayCopy(), $defaults);
        $this->setConfig($config);
    }

    protected function handleFormGeneric(ArrayObject $args, array $values): void
    {
        $defaults = [
            'endpoint' => null,
            'key_identity' => null,
            'key_credential' => null,
            'o:owner' => null,
            'types' => [],
        ];
        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        // TODO Manage check of duplicate identifiers during dry-run.
        // $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $args->exchangeArray($result);
    }
}
