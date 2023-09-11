<?php declare(strict_types=1);

namespace BulkImport\Controller\Admin;

use BulkImport\Api\Representation\ImporterRepresentation;
use BulkImport\Form\ImporterConfirmForm;
use BulkImport\Form\ImporterDeleteForm;
use BulkImport\Form\ImporterForm;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Job\Import as JobImport;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Session\Container;
use Laminas\View\Model\ViewModel;
use Log\Stdlib\PsrMessage;

class ImporterController extends AbstractActionController
{
    use ServiceLocatorAwareTrait;

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
    }

    public function indexAction()
    {
        return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer', 'action' => 'browse']);
    }

    public function browseAction()
    {
        $this->setBrowseDefaults('label', 'asc');

        $response = $this->api()->search('bulk_importers', ['sort_by' => 'label', 'sort_order' => 'asc']);
        $importers = $response->getContent();

        return new ViewModel([
            'importers' => $importers,
            'resources' => $importers,
        ]);
    }

    public function addAction()
    {
        return $this->editAction();
    }

    public function editAction()
    {
        /** @var \BulkImport\Api\Representation\ImporterRepresentation $importer */
        $id = (int) $this->params()->fromRoute('id');
        $importer = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;

        if ($id && !$importer) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer']);
        }

        $form = $this->getForm(ImporterForm::class);
        if ($importer) {
            $currentData = $importer->getJsonLd();
            $form->setData($currentData);
        }

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            if ($form->isValid()) {
                $data = $form->getData();
                unset($data['csrf'], $data['form_submit'], $data['current_form']);
                if (!$importer) {
                    $data['o:owner'] = $this->identity();
                    $response = $this->api($form)->create('bulk_importers', $data);
                } else {
                    $oConfig = $currentData['o:config'];
                    $oConfig['importer'] = $data['o:config']['importer'] ?? [];;
                    $data['o:config'] = $oConfig;
                    $response = $this->api($form)->update('bulk_importers', $this->params('id'), $data, [], ['isPartial' => true]);
                }

                if (!$response) {
                    $this->messenger()->addError('Save of importer failed'); // @translate
                    return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer'], true);
                } else {
                    $this->messenger()->addSuccess('Importer successfully saved'); // @translate
                    return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer', 'action' => 'browse'], true);
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'importer' => $importer,
            'form' => $form,
        ]);
    }

    public function deleteAction()
    {
        /** @var \BulkImport\Api\Representation\ImporterRepresentation $importer */
        $id = (int) $this->params()->fromRoute('id');
        $importer = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;

        if (!$importer) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer']);
        }

        // Check if the importer has imports.
        // Don't load entities if the only information needed is total results.
        $total = $this->api()->search('bulk_imports', ['importer_id' => $id, 'limit' => 0])->getTotalResults();
        if ($total) {
            $this->messenger()->addWarning('This importer cannot be deleted: imports that use it exist.'); // @translate
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer']);
        }

        $form = $this->getForm(ImporterDeleteForm::class);
        $form->setData($importer->getJsonLd());

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $response = $this->api($form)->delete('bulk_importers', $id);
                if ($response) {
                    $this->messenger()->addSuccess('Importer successfully deleted'); // @translate
                } else {
                    $this->messenger()->addError('Delete of importer failed'); // @translate
                }
                return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer']);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'importer' => $importer,
            'form' => $form,
        ]);
    }

    public function configureReaderAction()
    {
        /** @var \BulkImport\Api\Representation\ImporterRepresentation $importer */
        $id = (int) $this->params()->fromRoute('id');
        $importer = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;

        if (!$importer) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer']);
        }

        /** @var \BulkImport\Reader\Reader $reader */
        $reader = $importer->reader();
        $form = $this->getForm($reader->getConfigFormClass());
        $form->setAttribute('id', 'form-importer-reader');
        $readerConfig = $reader instanceof Configurable ? $reader->getConfig() : [];
        $form->setData($readerConfig);

        $form->add([
            'name' => 'form_submit',
            'type' => Fieldset::class,
        ]);
        $form->get('form_submit')->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'options' => [
                'label' => 'Save',
            ],
            'attributes' => [
                'value' => 'Save', // @translate
                'id' => 'submitbutton',
            ],
        ]);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $currentData = $importer->getJsonLd();
                $currentData['o:config']['reader'] = $reader->handleConfigForm($form)->getConfig();
                $update = ['o:config' => $currentData['o:config']];
                $response = $this->api($form)->update('bulk_importers', $this->params('id'), $update, [], ['isPartial' => true]);
                if ($response) {
                    $this->messenger()->addSuccess('Reader configuration saved'); // @translate
                } else {
                    $this->messenger()->addError('Save of reader configuration failed'); // @translate
                }
                return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer']);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'importer' => $importer,
            'reader' => $reader,
            'form' => $form,
        ]);
    }

    public function configureProcessorAction()
    {
        /** @var \BulkImport\Api\Representation\ImporterRepresentation $importer */
        $id = (int) $this->params()->fromRoute('id');
        $importer = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;

        if (!$importer) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer']);
        }

        /** @var \BulkImport\Processor\Processor $processor */
        $processor = $importer->processor();
        $form = $this->getForm($processor->getConfigFormClass());
        $form->setAttribute('id', 'form-importer-processor');
        $processorConfig = $processor instanceof Configurable ? $processor->getConfig() : [];
        $form->setData($processorConfig);

        $form->add([
            'name' => 'form_submit',
            'type' => Fieldset::class,
        ]);
        $form->get('form_submit')->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'options' => [
                'label' => 'Save',
            ],
            'attributes' => [
                'value' => 'Save', // @translate
                'id' => 'submitbutton',
            ],
        ]);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $currentData = $importer->getJsonLd();
                $currentData['o:config']['processor'] = $processor->handleConfigForm($form)->getConfig();
                $update = ['o:config' => $currentData['o:config']];
                $response = $this->api($form)->update('bulk_importers', $this->params('id'), $update, [], ['isPartial' => true]);
                if ($response) {
                    $this->messenger()->addSuccess('Processor configuration saved'); // @translate
                } else {
                    $this->messenger()->addError('Save of processor configuration failed'); // @translate
                }
                return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'importer']);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'importer' => $importer,
            'processor' => $processor,
            'form' => $form,
        ]);
    }

    /**
     * Process a bulk import by step: reader, mapper, processor and confirm.
     *
     * @todo Simplify code of this three steps process.
     * @todo Move to ImportController.
     *
     * @return \Laminas\Http\Response|\Laminas\View\Model\ViewModel
     */
    public function startAction()
    {
        $id = (int) $this->params()->fromRoute('id');

        /** @var \BulkImport\Api\Representation\ImporterRepresentation $importer */
        $importer = $id ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;
        if (!$importer) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        /** @var \BulkImport\Reader\Reader $reader */
        $reader = $importer->reader();
        if (!$reader) {
            $message = new PsrMessage('Reader "{reader}" does not exist', ['reader' => $importer->readerClass()]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        /** @var \BulkImport\Processor\Processor $processor*/
        $processor = $importer->processor();
        if (!$processor) {
            $message = new PsrMessage('Processor "{processor}" does not exist', ['processor' => $importer->processorClass()]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        /** @var \Laminas\Session\SessionManager $sessionManager */
        $sessionManager = Container::getDefaultManager();
        $session = new Container('BulkImport', $sessionManager);

        if (!$this->getRequest()->isPost()) {
            $session->exchangeArray([]);
        }
        if (isset($session->reader)) {
            $reader->setParams($session->reader);
        }
        if (isset($session->processor)) {
            $processor->setParams($session->processor);
        }

        $formsCallbacks = $this->getStartFormsCallbacks($importer);
        $formCallback = reset($formsCallbacks);

        $asTask = $importer->configOption('importer', 'as_task');
        if ($asTask) {
            $message = new PsrMessage('This import will be stored to be run as a task.'); // @translate
            $this->messenger()->addWarning($message);
        }

        $next = null;
        if ($this->getRequest()->isPost()) {
            // Current form.
            $currentForm = $this->getRequest()->getPost('current_form');

            // Avoid an issue if the user reloads the page.
            if (!isset($formsCallbacks[$currentForm])) {
                $message = new PsrMessage('The page was reloaded, but params are lost. Restart the import.'); // @translate
                $this->messenger()->addError($message);
                return $this->redirect()->toRoute('admin/bulk');
            }

            $form = call_user_func($formsCallbacks[$currentForm]);

            // Make certain to merge the files info!
            $request = $this->getRequest();
            $postData = $request->getPost()->toArray();
            $postFiles = $request->getFiles()->toArray();
            $data = array_merge_recursive($postData, $postFiles);

            // Pass data to form.
            $form->setData($data);
            if ($form->isValid()) {
                // Execute file filters.
                $data = $form->getData();
                unset($data['csrf'], $data['form_submit'], $data['current_form']);
                $session->{$currentForm} = $data;
                switch ($currentForm) {
                    default:
                    case 'reader':
                        $reader->handleParamsForm($form);
                        $session->reader = $reader->getParams();
                        if (method_exists($reader, 'currentSheetName')) {
                            try {
                                $sheetName = $reader->currentSheetName();
                            } catch (\Exception $e) {
                                $sheetName = null;
                            }
                            if ($sheetName) {
                                $this->messenger()->addSuccess(new PsrMessage(
                                    'Current sheet: "{name}"', // @translate
                                    ['name' => $sheetName]
                                ));
                            }
                        }
                        // Some readers can't get the total of resources: sql,
                        // omekas, etc., so don't go back if empty.
                        $isCountable = $reader instanceof \BulkImport\Reader\SpreadsheetReader
                            || $reader instanceof \BulkImport\Reader\AbstractSpreadsheetFileReader;
                        if ($isCountable && method_exists($reader, 'count')) {
                            try {
                                $count = $reader->count();
                            } catch (\Exception $e) {
                                $count = 0;
                                $next = 'reader';
                                $this->messenger()->addError($reader->getLastErrorMessage());
                            }
                            if ($count) {
                                $this->messenger()->addSuccess(new PsrMessage(
                                    'Total resources, items or rows: {total}', // @translate
                                    ['total' => $count]
                                ));
                            } else {
                                $this->messenger()->addWarning(new PsrMessage(
                                    'The source seems to have no resource to import or the total cannot be determined.' // @translate
                                ));
                            }
                        }
                        if (!$reader->isValid()) {
                            $next = 'reader';
                            $this->messenger()->addError($reader->getLastErrorMessage());
                        } elseif ($isCountable && method_exists($reader, 'count') && empty($count)) {
                            $next = 'reader';
                        } else {
                            // Only manual mapping is managed here (spreadsheet).
                            $next = isset($formsCallbacks['mapping']) ? 'mapping' : 'processor';
                        }
                        $formCallback = $formsCallbacks[$next];
                        break;

                    case 'mapping':
                        // There is a complex issue for mapping names with diacritics,
                        // spaces or quotes, for example customvocab:"Autorités".
                        // So serialize mapping with original post for now.
                        // TODO Fix laminas for form element name with diacritics.
                        $session->mapping = serialize($postData['mapping'] ?? []);
                        $next = isset($formsCallbacks['processor']) ? 'processor' : 'confirm';
                        $formCallback = $formsCallbacks[$next];
                        break;

                    case 'processor':
                        $processor->handleParamsForm($form, $session->mapping ?? null);
                        $session->comment = trim((string) $data['comment']);
                        $session->processor = $processor->getParams();
                        $next = 'confirm';
                        $formCallback = $formsCallbacks['confirm'];
                        break;

                    case 'confirm':
                        $importData = [];
                        $importData['o-bulk:comment'] = trim((string) $session['comment']) ?: null;
                        $importData['o-bulk:importer'] = $importer->getResource();
                        if ($processor instanceof Parametrizable) {
                            $processorParams = $processor->getParams();
                            unset($processorParams['mapping']);
                        } else {
                            $processorParams = null;
                        }
                        $importData['o:params'] = [
                            'reader' => $reader instanceof Parametrizable ? $reader->getParams() : null,
                            'mapping' => empty($session->mapping) ? null :  unserialize($session->mapping),
                            'processor' => $processorParams,
                        ];
                        $response = $this->api()->create('bulk_imports', $importData);
                        if (!$response) {
                            $this->messenger()->addError('Save of import failed'); // @translate
                            break;
                        }

                        /** @var \BulkImport\Api\Representation\ImportRepresentation $import */
                        $import = $response->getContent();

                        // Don't run job if it is configured as a task.
                        if ($asTask) {
                            $message = new PsrMessage(
                                'The import #{bulk_import} was stored for future use.', // @translate
                                ['bulk_import' => $import->id()]
                            );
                            $this->messenger()->addSuccess($message);
                            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'bulk-import']);
                        }

                        // Clear import session.
                        $session->exchangeArray([]);

                        $args = [
                            'bulk_import_id' => $import->id(),
                        ];

                        $dispatcher = $this->jobDispatcher();
                        try {
                            // Synchronous dispatcher for quick testing purpose.
                            // $job = $dispatcher->dispatch(JobImport::class, $args, $this->getServiceLocator()->get('Omeka\Job\DispatchStrategy\Synchronous'));
                            $job = $dispatcher->dispatch(JobImport::class, $args);
                            $urlPlugin = $this->url();
                            $message = new PsrMessage(
                                'Import started in background (job {link_open_job}#{jobId}{link_close}, {link_open_log}logs{link_close}). This may take a while.', // @translate
                                [
                                    'link_open_job' => sprintf(
                                        '<a href="%s">',
                                        htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                                    ),
                                    'jobId' => $job->getId(),
                                    'link_close' => '</a>',
                                    'link_open_log' => sprintf(
                                        '<a href="%s">',
                                        htmlspecialchars($urlPlugin->fromRoute('admin/bulk/id', ['controller' => 'import', 'action' => 'logs', 'id' => $import->id()]))
                                    ),
                                ]
                            );
                            $message->setEscapeHtml(false);
                            $this->messenger()->addSuccess($message);
                        } catch (\Exception $e) {
                            $this->messenger()->addError('Import start failed'); // @translate
                        }

                        return $this->redirect()->toRoute('admin/bulk');
                }

                // Next form.
                $form = call_user_func($formCallback);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        // Default form.
        if (!isset($form)) {
            $form = call_user_func($formCallback);
        }

        if ($form instanceof \Laminas\Http\PhpEnvironment\Response) {
            return $form;
        }

        $messagePre = '';
        if ($form instanceof \BulkImport\Form\Reader\SpreadsheetReaderConfigForm) {
            $messagePre = sprintf(
                $this->translate('See the %1$sread me%2$s to learn how to write spreadsheets headers for a quick mapping, with data type and language. Example : %3$s'), // @translate
                '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport#spreadsheet">', '</a>', '<br/>dcterms:date ^^timestamp ^^literal @fra §private'
            );
        }

        $messagePost = '';
        if ($form instanceof \BulkImport\Form\Reader\XmlReaderConfigForm
            && $reader instanceof \BulkImport\Reader\XmlReader
        ) {
            $reader->setParams([
                'xsl_sheet_pre' => $form->get('xsl_sheet_pre')->getValue(),
                'xsl_sheet' => $form->get('xsl_sheet')->getValue(),
            ]);
            $comments = $reader->getConfigMainComments();
            foreach ($comments as $file => $comment) {
                $messagePost .= '<h4>' . $file . '</h4>';
                $messagePost .= '<p>' . nl2br($this->viewHelpers()->get('escapeHtml')($comment)) . '</p>';
            }
        }

        // TODO Add comments for mapper? There is only one for now in config. Add settings for "allowed mapper"?
        // 'mapper' => $form->get('mapper')->getValue(),

        $view = new ViewModel([
            'importer' => $importer,
            'form' => $form,
            'messagePre' => $messagePre,
            'messagePost' => $messagePost,
            'step' => $next ?? 'reader',
            'steps' => array_keys(array_filter($formsCallbacks)),
        ]);

        if ($next === 'confirm') {
            $importArgs = [];
            $importArgs['comment'] = $session->comment;
            $importArgs['reader'] = $session->reader;
            $importArgs['mapping'] = isset($session->mapping) ? unserialize($session->mapping) : null;
            $importArgs['processor'] = $currentForm === 'processor' ? $session->processor ?? [] : [];
            // For security purpose.
            unset($importArgs['reader']['filename']);
            foreach ($importArgs['processor']['files'] ?? [] as $key => $file) {
                unset($importArgs['processor']['files'][$key]['filename']);
                unset($importArgs['processor']['files'][$key]['tmp_name']);
            }
            unset($importArgs['processor']['mapping']);
            $view
                ->setVariable('importArgs', $importArgs);
        }

        return $view;
    }

    /**
     * @todo Replace by a standard multi-steps form without callback.
     */
    protected function getStartFormsCallbacks(ImporterRepresentation $importer)
    {
        $controller = $this;
        $formsCallbacks = [];

        $reader = $importer->reader();
        if ($reader instanceof Parametrizable) {
            /* @return \Laminas\Form\Form */
            $formsCallbacks['reader'] = function () use ($reader, $importer, $controller) {
                try {
                    $readerForm = $controller->getForm($reader->getParamsFormClass());
                } catch (\Omeka\Service\Exception\RuntimeException $e) {
                    $message = new PsrMessage(
                        'Importer #{importer} has error: {error}', // @translate
                        ['importer' => $importer->label(), 'error' => $e->getMessage()]
                    );
                    $this->messenger()->addError($message);
                    return $this->redirect()->toRoute('admin/bulk');
                }
                $readerConfig = $reader instanceof Configurable ? $reader->getConfig() : [];
                $readerForm->setData($readerConfig);

                $readerForm
                    ->add([
                        'name' => 'current_form',
                        'type' => Element\Hidden::class,
                        'attributes' => [
                            'value' => 'reader',
                        ],
                    ])
                    ->add([
                        'name' => 'form_submit',
                        'type' => Fieldset::class,
                    ])
                    ->get('form_submit')
                    ->add([
                        'name' => 'submit',
                        'type' => Element\Submit::class,
                        'attributes' => [
                            'value' => 'Continue', // @translate
                        ],
                    ]);

                return $readerForm;
            };
        }

        $processor = $importer->processor();

        $mapper = $importer->mapper();
        if ($mapper === 'manual') {
            /* @return \Laminas\Form\Form */
            $formsCallbacks['mapping'] = function () use ($reader, $processor, $importer, $controller) {
                $mapForms = [
                    \BulkImport\Form\Processor\AssetProcessorParamsForm::class => \BulkImport\Form\Mapping\AssetMappingParamsForm::class,
                    \BulkImport\Form\Processor\ItemProcessorParamsForm::class => \BulkImport\Form\Mapping\ItemMappingParamsForm::class,
                    \BulkImport\Form\Processor\MediaProcessorParamsForm::class => \BulkImport\Form\Mapping\MediaMappingParamsForm::class,
                    \BulkImport\Form\Processor\ItemSetProcessorParamsForm::class => \BulkImport\Form\Mapping\ItemSetMappingParamsForm::class,
                    \BulkImport\Form\Processor\ResourceProcessorParamsForm::class => \BulkImport\Form\Mapping\ResourceMappingParamsForm::class,
                ];
                $processorFormClass = $processor->getParamsFormClass();
                $mappingFormClass = $mapForms[$processorFormClass] ?? \BulkImport\Form\Mapping\ResourceMappingParamsForm::class;
                $availableFields = $reader->getAvailableFields();
                try {
                    $mappingForm = $controller->getForm($mappingFormClass, [
                        'availableFields' => $availableFields,
                    ]);
                } catch (\Omeka\Service\Exception\RuntimeException $e) {
                    $message = new PsrMessage(
                        'Importer #{importer} has error: {error}', // @translate
                        ['importer' => $importer->label(), 'error' => $e->getMessage()]
                    );
                    $this->messenger()->addError($message);
                    return $this->redirect()->toRoute('admin/bulk');
                }

                $mappingForm
                    ->add([
                        'name' => 'current_form',
                        'type' => Element\Hidden::class,
                        'attributes' => [
                            'value' => 'mapping',
                        ],
                    ])
                    ->add([
                        'name' => 'form_submit',
                        'type' => Fieldset::class,
                    ])
                    ->get('form_submit')
                    ->add([
                        'name' => 'submit',
                        'type' => Element\Submit::class,
                        'attributes' => [
                            'value' => 'Continue', // @translate
                        ],
                    ]);

                return $mappingForm;
            };
        }

        if ($processor instanceof Parametrizable) {
            /* @return \Laminas\Form\Form */
            $formsCallbacks['processor'] = function () use ($processor, $importer, $controller) {
                try {
                    $processorForm = $controller->getForm($processor->getParamsFormClass());
                } catch (\Omeka\Service\Exception\RuntimeException $e) {
                    $message = new PsrMessage(
                        'Importer #{importer} has error: {error}', // @translate
                        ['importer' => $importer->label(), 'error' => $e->getMessage()]
                    );
                    $this->messenger()->addError($message);
                    return $this->redirect()->toRoute('admin/bulk');
                }
                $processorConfig = $processor instanceof Configurable ? $processor->getConfig() : [];
                $processorForm->setData($processorConfig);

                $processorForm
                    ->add([
                        'name' => 'current_form',
                        'type' => Element\Hidden::class,
                        'attributes' => [
                            'value' => 'processor',
                        ],
                    ])
                    ->add([
                        'name' => 'form_submit',
                        'type' => Fieldset::class,
                    ])
                    ->get('form_submit')
                    ->add([
                        'name' => 'submit',
                        'type' => Element\Submit::class,
                        'attributes' => [
                            'value' => 'Continue', // @translate
                        ],
                    ]);

                return $processorForm;
            };
        }

        /* @return \Laminas\Form\Form */
        $formsCallbacks['confirm'] = function () use ($controller) {
            $startForm = $controller->getForm(ImporterConfirmForm::class);
            $startForm
                ->add([
                    'name' => 'current_form',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'value' => 'confirm',
                    ],
                ]);
                // Submit is in the fieldset.
            return $startForm;
        };

        return $formsCallbacks;
    }
}
