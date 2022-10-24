<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class AssetProcessorConfigForm extends Form
{
    use CommonProcessTrait;

    public function init(): void
    {
        $this
            ->baseFieldset();

        $this
            ->baseInputFilter();
    }

    protected function baseFieldset()
    {
        $this
            ->addCommonProcess()

            ->add([
                'name' => 'action',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Action', // @translate
                    'value_options' => [
                        // Options are simpler than resources because there is
                        // only one metadata by field (single table).
                        \BulkImport\Processor\AbstractProcessor::ACTION_CREATE => 'Create new assets', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE => 'Update data of assets or attach to resources', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_DELETE => 'Delete assets', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_SKIP => 'Skip entries (dry run)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action',
                    'multiple' => false,
                    'required' => false,
                    'class' => 'chosen-select',
                ],
            ])

            ->add([
                'name' => 'action_unidentified',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Action on unidentified assets and resources during update', // @translate
                    'value_options' => [
                        \BulkImport\Processor\AbstractProcessor::ACTION_SKIP => 'Skip entry', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_CREATE => 'Create a new asset', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action_unidentified',
                    'value' => \BulkImport\Processor\AbstractProcessor::ACTION_SKIP,
                ],
            ])

            ->add([
                'name' => 'identifier_name',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Identifier to use to update asset or to attach to a resource as thumbnail', // @translate
                    'empty_option' => '',
                    'prepend_value_options' => [
                        'o:id' => 'Internal id', // @translate
                        'asset_identifier' => [
                            'label' => 'Asset identifier', // @translate
                            'options' => [
                                'o:name' => 'Source base file name or url', // @translate
                                // 'o:filename' => 'Stored file name', // @translate
                                'o:storage_id' => 'Storage id', // @translate
                            ],
                        ],
                        'media_identifier' => [
                            'label' => 'Media identifier', // @translate
                            'options' => [
                                'o:source' => 'Source', // @translate
                                'o:filename' => 'File name', // @translate
                                // When used with module Archive Repertory.
                                'o:basename' => 'Base file name', // @translate
                                'o:storage_id' => 'Storage id', // @translate
                                'o:sha256' => 'Hash', // @translate
                            ],
                        ],
                    ],
                    'term_as_value' => true,
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'identifier_name',
                    'multiple' => true,
                    'required' => false,
                    'value' => [
                        'o:id',
                    ],
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select an identifier name…', // @translate
                ],
            ])

            ->addOwner()
        ;

        return $this;
    }

    protected function baseInputFilter()
    {
        $this
            ->addCommonProcessInputFilter()
            ->addOwnerInputFilter()

            ->getInputFilter()
            ->add([
                'name' => 'action',
                'required' => false,
            ])
            ->add([
                'name' => 'action_unidentified',
                'required' => false,
            ])
            ->add([
                'name' => 'identifier_name',
                'required' => false,
            ])
        ;
        return $this;
    }
}
