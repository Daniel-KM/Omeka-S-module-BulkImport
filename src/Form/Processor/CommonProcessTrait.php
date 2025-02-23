<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

trait CommonProcessTrait
{
    use ServiceLocatorAwareTrait;

    protected function addCommonProcess(): self
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
                'type' => CommonElement\OptionalRadio::class,
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
                    'label' => 'Don’t stop process when a file attached to an item is missing (import item metadata only)', // @translate
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
                'name' => 'clean',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Clean strings', // @translate
                    'value_options' => [
                        'trim' => 'Trim', // @translate
                        'merge_space' => 'Replace consecutive spaces by a single space', // @translate
                        'trim_punctuation' => 'Remove trailing punctuation', // @translate
                        'lowercase' => 'Lower case for string', // @translate
                        'ucfirst' => 'Lower case for string and upper case for first letter', // @translate
                        'ucwords' => 'Lower case for string and upper case for each word', // @translate
                        'uppercase' => 'Upper case for string', // @translate
                        'apostrophe' => 'Replace single quote by apostrophe', // @translate
                        'single_quote' => 'Replace apostrophe by single quote', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'clean',
                ],
            ])

            ->add([
                'name' => 'info_diffs',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Output differences', // @translate
                    'info' => 'Get informations about differences between existing resources and new values.', // @translate
                ],
                'attributes' => [
                    'id' => 'info_diffs',
                ],
            ])
        ;
        return $this;
    }

    protected function addOwner(): self
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

    protected function addFiles(): self
    {
        // Set binary content encoding
        $this
            ->setAttribute('enctype', 'multipart/form-data');

        $this
            ->add([
                'name' => 'files',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Files to store', // @translate
                ],
            ]);

        $this
            ->get('files')
            ->add([
                'name' => 'files',
                'type' => Element\File::class,
                'options' => [
                    'label' => 'Files to store on the server before import (multiple individual files or zipped)', // @translate
                    'info' => 'When access to server is complex or when it cannot access internet or other servers, it is possible to upload them here.', // @translate
                ],
                'attributes' => [
                    'id' => 'files',
                    'required' => false,
                    'multiple' => true,
                ],
            ]);

        return $this;
    }

    protected function addCommonProcessInputFilter(): self
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
        ;
        return $this;
    }

    protected function addOwnerInputFilter(): self
    {
        $this->getInputFilter()
            ->add([
                'name' => 'o:owner',
                'required' => false,
            ]);
        return $this;
    }

    protected function addFilesFilter(): self
    {
        $this->getInputFilter()
            ->get('files')
            ->add([
                'name' => 'files',
                'required' => false,
            ]);
        return $this;
    }
}
