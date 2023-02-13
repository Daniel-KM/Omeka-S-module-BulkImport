<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Form\Element as BulkImportElement;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Omeka\Form\Element as OmekaElement;

trait CommonProcessTrait
{
    use ServiceLocatorAwareTrait;

    protected function addCommonProcess(): \Laminas\Form\Form
    {
        $this
            ->add([
                'name' => 'comment',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Label or comment', // @translate
                    'info' => 'This optional comment will help admins for future reference.', // @translate
                ],
                'attributes' => [
                    'id' => 'comment',
                    'value' => '',
                    'placeholder' => 'Optional label or comment for future reference.', // @translate
                ],
            ])

            ->add([
                'name' => 'processing',
                'type' => BulkImportElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Process control', // @translate
                    'info' => 'In all cases, the check of identifiers, linked resources, template values, and files presence is done during a first loop.', // @translate
                    'value_options' => [
                        'dry_run' => 'Dry run', // @translate
                        'stop_on_error' => 'Stop on error', // @translate
                        'continue_on_error' => 'Continue on error', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'processing',
                    'value' => 'stop_on_error',
                ],
            ])

            ->add([
                'name' => 'skip_missing_files',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Don’t stop process when a file is missing (import item metadata only)', // @translate
                ],
                'attributes' => [
                    'id' => 'skip_missing_files',
                ],
            ])

            ->add([
                'name' => 'entries_to_skip',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of entries to skip', // @translate
                    'info' => 'Allows to start on a specific entry index, in particular when there was an issue in first rows of a spreadsheet. Note: don’t include the header row.', // @translate
                ],
                'attributes' => [
                    'id' => 'entries_to_skip',
                    'min' => '0',
                    'step' => '1',
                    'aria-label' => 'Number of entries to skip', // @translate
                ],
            ])
            ->add([
                'name' => 'entries_max',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Max number of entries to process', // @translate
                ],
                'attributes' => [
                    'id' => 'entries_max',
                    'min' => '0',
                    'step' => '1',
                    'aria-label' => 'Max number of entries to process', // @translate
                ],
            ])

            ->add([
                'name' => 'store_as_task',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Store import as a task', // @translate
                    'info' => 'Allows to store a job to run it via command line or a cron task (see module EasyAdmin).', // @translate
                ],
                'attributes' => [
                    'id' => 'store_as_task',
                ],
            ])
        ;
        return $this;
    }

    protected function addOwner(): \Laminas\Form\Form
    {
        $services = $this->getServiceLocator();
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $this
            ->add([
                'name' => 'o:owner',
                'type' => OmekaElement\ResourceSelect::class,
                'options' => [
                    'label' => 'Owner', // @translate
                    'prepend_value_options' => [
                        'current' => 'Current user', // @translate
                    ],
                    'resource_value_options' => [
                        'resource' => 'users',
                        'query' => ['sort_by' => 'name', 'sort_dir' => 'ASC'],
                        'option_text_callback' => function ($user) {
                            return sprintf('%s (%s)', $user->name(), $user->email());
                        },
                    ],
                ],
                'attributes' => [
                    'id' => 'select-owner',
                    'value' => 'current',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a user', // @translate
                    'data-api-base-url' => $urlHelper('api/default', ['resource' => 'users'], ['query' => ['sort_by' => 'email', 'sort_dir' => 'ASC']]),
                ],
            ])
        ;
        return $this;
    }

    protected function addCommonProcessInputFilter(): \Laminas\Form\Form
    {
        $this->getInputFilter()
            ->add([
                'name' => 'entries_to_skip',
                'required' => false,
            ])
            ->add([
                'name' => 'entries_max',
                'required' => false,
            ])

            ->add([
                'name' => 'store_as_task',
                'required' => false,
            ])
        ;
        return $this;
    }

    protected function addOwnerInputFilter(): \Laminas\Form\Form
    {
        $this->getInputFilter()
            ->add([
                'name' => 'o:owner',
                'required' => false,
            ]);
        return $this;
    }
}
