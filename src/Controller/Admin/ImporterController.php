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
            $data = $importer->getJsonLd();
            $form->setData($data);
        }

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            if ($form->isValid()) {
                $data = $form->getData();
                if ($importer) {
                    $response = $this->api($form)->update('bulk_importers', $this->params('id'), $data, [], ['isPartial' => true]);
                } else {
                    $data['o:owner'] = $this->identity();
                    $response = $this->api($form)->create('bulk_importers', $data);
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
        $form->setAttribute('id', 'importer-reader-form');
        $readerConfig = $reader instanceof Configurable ? $reader->getConfig() : [];
        $form->setData($readerConfig);

        $form->add([
            'name' => 'importer_submit',
            'type' => Fieldset::class,
        ]);
        $form->get('importer_submit')->add([
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
                $reader->handleConfigForm($form);

                $data['reader_config'] = $reader->getConfig();
                $response = $this->api($form)->update('bulk_importers', $this->params('id'), $data, [], ['isPartial' => true]);

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
        $form->setAttribute('id', 'importer-processor-form');
        $processorConfig = $processor instanceof Configurable ? $processor->getConfig() : [];
        $form->setData($processorConfig);

        $form->add([
            'name' => 'importer_submit',
            'type' => Fieldset::class,
        ]);
        $form->get('importer_submit')->add([
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
                $processor->handleConfigForm($form);

                $update = ['processor_config' => $processor->getConfig()];
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
     * The process to start a bulk import uses, if any,  the reader form, the
     * processor form and the confirm form.
     *
     * @todo Simplify code of this three steps process.
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

        $reader = $importer->reader();
        if (!$reader) {
            $message = new PsrMessage('Reader "{reader}" does not exist', ['reader' => $importer->readerClass()]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        $processor = $importer->processor();
        if (!$processor) {
            $message = new PsrMessage('Processor "{processor}" does not exist', ['processor' => $importer->processorClass()]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        $processor->setReader($reader);

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
            $data = array_merge_recursive(
                $request->getPost()->toArray(),
                $request->getFiles()->toArray()
            );

            // Pass data to form.
            $form->setData($data);
            if ($form->isValid()) {
                // Execute file filters.
                $data = $form->getData();

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
                            $next = isset($formsCallbacks['processor']) ? 'processor' : 'confirm';
                        }
                        $formCallback = $formsCallbacks[$next];
                        break;

                    case 'processor':
                        $processor->handleParamsForm($form);
                        $session->comment = trim((string) $data['comment']);
                        $session->storeAsTask = !empty($data['store_as_task']);
                        $session->processor = $processor->getParams();
                        $next = 'confirm';
                        $formCallback = $formsCallbacks['confirm'];
                        if (!empty($session->storeAsTask)) {
                            $message = new PsrMessage('This import will be stored to be run as a task.'); // @translate
                            $this->messenger()->addWarning($message);
                        }
                        break;

                    case 'confirm':
                        $importData = [];
                        $importData['o-bulk:comment'] = trim((string) $session['comment']) ?: null;
                        $importData['o-bulk:importer'] = $importer->getResource();
                        if ($reader instanceof Parametrizable) {
                            $importData['o-bulk:reader_params'] = $reader->getParams();
                        }
                        if ($processor instanceof Parametrizable) {
                            $importData['o-bulk:processor_params'] = $processor->getParams();
                        }

                        $response = $this->api()->create('bulk_imports', $importData);
                        if (!$response) {
                            $this->messenger()->addError('Save of import failed'); // @translate
                            break;
                        }

                        /** @var \BulkImport\Api\Representation\ImportRepresentation $import */
                        $import = $response->getContent();

                        // Don't run job if it is a task.
                        if (!empty($session->storeAsTask)) {
                            $message = new PsrMessage(
                                'The import #{bulk_import} was stored as a task.', // @translate
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
                            // Synchronous dispatcher for testing purpose.
                            // $job = $dispatcher->dispatch(JobImport::class, $args, $this->getServiceLocator()->get('Omeka\Job\DispatchStrategy\Synchronous'));
                            $job = $dispatcher->dispatch(JobImport::class, $args);
                            $urlHelper = $this->url();
                            $message = new PsrMessage(
                                'Import started in background (job {link_open_job}#{jobId}{link_close}, {link_open_log}logs{link_close}). This may take a while.', // @translate
                                [
                                    'link_open_job' => sprintf(
                                        '<a href="%s">',
                                        htmlspecialchars($urlHelper->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                                    ),
                                    'jobId' => $job->getId(),
                                    'link_close' => '</a>',
                                    'link_open_log' => sprintf(
                                        '<a href="%s">',
                                        htmlspecialchars($urlHelper->fromRoute('admin/bulk/id', ['controller' => 'import', 'action' => 'logs', 'id' => $import->id()]))
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
                '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport#spreadsheet">', '</a>', 'dcterms:date ^^timestamp ^^literal @fra §private'
            );
        }

        $messagePost = '';
        if ($form instanceof \BulkImport\Form\Reader\XmlReaderConfigForm
            && $reader instanceof \BulkImport\Reader\XmlReader
        ) {
            $reader->setParams([
                'xsl_sheet_pre' => $form->get('xsl_sheet_pre')->getValue(),
                'xsl_sheet' => $form->get('xsl_sheet')->getValue(),
                'mapping_config' => $form->get('mapping_config')->getValue(),
            ]);
            $comments = $reader->getConfigMainComments();
            foreach ($comments as $file => $comment) {
                $messagePost .= '<h4>' . $file . '</h4>';
                $messagePost .= '<p>' . nl2br($this->viewHelpers()->get('escapeHtml')($comment)) . '</p>';
            }
        }

        $view = new ViewModel([
            'importer' => $importer,
            'form' => $form,
            'storeAsTask' => !empty($session->storeAsTask),
            'messagePre' => $messagePre,
            'messagePost' => $messagePost,
        ]);

        if ($next === 'confirm') {
            $importArgs = [];
            $importArgs['comment'] = $session['comment'];
            $importArgs['reader'] = $session['reader'];
            $importArgs['processor'] = $currentForm === 'reader' ? [] : $session['processor'];
            // For security purpose.
            unset($importArgs['reader']['filename']);
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
                        'name' => 'reader_submit',
                        'type' => Fieldset::class,
                    ])
                    ->get('reader_submit')
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
        $processor->setReader($reader);
        if ($processor instanceof Parametrizable) {
            /* @return \Laminas\Form\Form */
            $formsCallbacks['processor'] = function () use ($processor, $importer, $controller) {
                try {
                    $processorForm = $controller->getForm($processor->getParamsFormClass(), [
                        'processor' => $processor,
                    ]);
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
                        'name' => 'reader_submit',
                        'type' => Fieldset::class,
                    ])
                    ->get('reader_submit')
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
            return $startForm;
        };

        return $formsCallbacks;
    }
}
