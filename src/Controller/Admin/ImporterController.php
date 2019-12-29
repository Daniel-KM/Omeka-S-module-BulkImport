<?php
namespace BulkImport\Controller\Admin;

use BulkImport\Api\Representation\ImporterRepresentation;
use BulkImport\Form\ImporterDeleteForm;
use BulkImport\Form\ImporterForm;
use BulkImport\Form\ImporterStartForm;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Job\Import as JobImport;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Log\Stdlib\PsrMessage;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Session\Container;
use Zend\View\Model\ViewModel;

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

    public function addAction()
    {
        return $this->editAction();
    }

    public function editAction()
    {
        $id = (int) $this->params()->fromRoute('id');
        /** @var \BulkImport\Api\Representation\ImporterRepresentation $entity */
        $entity = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;

        if ($id && !$entity) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        $form = $this->getForm(ImporterForm::class);
        if ($entity) {
            $data = $entity->getJsonLd();
            $form->setData($data);
        }

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                if ($entity) {
                    $response = $this->api($form)->update('bulk_importers', $this->params('id'), $data, [], ['isPartial' => true]);
                } else {
                    $data['o:owner'] = $this->identity();
                    $response = $this->api($form)->create('bulk_importers', $data);
                }

                if ($response) {
                    $this->messenger()->addSuccess('Importer successfully saved'); // @translate
                    return $this->redirect()->toRoute('admin/bulk');
                } else {
                    $this->messenger()->addError('Save of importer failed'); // @translate
                    return $this->redirect()->toRoute('admin/bulk');
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }

    public function deleteAction()
    {
        $id = (int) $this->params()->fromRoute('id');
        $entity = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;

        if (!$entity) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        // Check if the importer has imports.
        $total = $this->api()->search('bulk_imports', ['importer_id' => $id])->getTotalResults();
        if ($total) {
            $this->messenger()->addWarning('This importerd cannot be deleted: imports that use it exist.'); // @translate
            return $this->redirect()->toRoute('admin/bulk');
        }

        $form = $this->getForm(ImporterDeleteForm::class);
        $form->setData($entity->getJsonLd());

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $response = $this->api($form)->delete('bulk_importers', $id);
                if ($response) {
                    $this->messenger()->addSuccess('Importer successfully deleted'); // @translate
                    return $this->redirect()->toRoute('admin/bulk');
                } else {
                    $this->messenger()->addError('Delete of importer failed'); // @translate
                    return $this->redirect()->toRoute('admin/bulk');
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('entity', $entity);
        $view->setVariable('form', $form);
        return $view;
    }

    public function configureReaderAction()
    {
        /** @var \BulkImport\Api\Representation\ImporterRepresentation $entity */
        $id = (int) $this->params()->fromRoute('id');
        $entity = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;

        if (!$entity) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        /** @var \BulkImport\Interfaces\Reader $reader */
        $reader = $entity->reader();
        $form = $this->getForm($reader->getConfigFormClass());
        $readerConfig = $reader instanceof Configurable ? $reader->getConfig() : [];
        $form->setData($readerConfig);

        $form->add([
            'name' => 'importer_submit',
            'type' => Fieldset::class,
        ]);
        $form->get('importer_submit')->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
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
                    return $this->redirect()->toRoute('admin/bulk');
                } else {
                    $this->messenger()->addError('Save of reader configuration failed'); // @translate
                    return $this->redirect()->toRoute('admin/bulk');
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('reader', $reader);
        $view->setVariable('form', $form);
        return $view;
    }

    public function configureProcessorAction()
    {
        $id = (int) $this->params()->fromRoute('id');
        $entity = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;

        if (!$entity) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        /** @var \BulkImport\Interfaces\Processor $processor */
        $processor = $entity->processor();
        $form = $this->getForm($processor->getConfigFormClass());
        $processorConfig = $processor instanceof Configurable ? $processor->getConfig() : [];
        $form->setData($processorConfig);

        $form->add([
            'name' => 'importer_submit',
            'type' => Fieldset::class,
        ]);
        $form->get('importer_submit')->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
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
                    return $this->redirect()->toRoute('admin/bulk');
                } else {
                    $this->messenger()->addError('Save of processor configuration failed'); // @translate
                    return $this->redirect()->toRoute('admin/bulk');
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('processor', $processor);
        $view->setVariable('form', $form);
        return $view;
    }

    public function startAction()
    {
        $id = (int) $this->params()->fromRoute('id');

        /** @var \BulkImport\Api\Representation\ImporterRepresentation $importer */
        $importer = ($id) ? $this->api()->searchOne('bulk_importers', ['id' => $id])->getContent() : null;
        if (!$importer) {
            $message = new PsrMessage('Importer #{importer_id} does not exist', ['importer_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk');
        }

        $reader = $importer->reader();
        $processor = $importer->processor();
        $processor->setReader($reader);

        /** @var \Zend\Session\SessionManager $sessionManager */
        $sessionManager = Container::getDefaultManager();
        $session = new Container('ImporterStartForm', $sessionManager);

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
                        if (!$reader->isValid()) {
                            $this->messenger()->addError($reader->getLastErrorMessage());
                            $next = 'reader';
                        } else {
                            $next = isset($formsCallbacks['processor']) ? 'processor' : 'start';
                        }
                        $formCallback = $formsCallbacks[$next];
                        break;

                    case 'processor':
                        $processor->handleParamsForm($form);
                        $session->processor = $processor->getParams();
                        $next = 'start';
                        $formCallback = $formsCallbacks['start'];
                        break;

                    case 'start':
                        $importData = [];
                        $importData['o-module-bulk:importer'] = $importer->getResource();
                        if ($reader instanceof Parametrizable) {
                            $importData['o-module-bulk:reader_params'] = $reader->getParams();
                        }
                        if ($processor instanceof Parametrizable) {
                            $importData['o-module-bulk:processor_params'] = $processor->getParams();
                        }

                        $response = $this->api()->create('bulk_imports', $importData);
                        if (!$response) {
                            $this->messenger()->addError('Save of import failed'); // @translate
                            break;
                        }
                        $import = $response->getContent();

                        // Clear import session.
                        $session->exchangeArray([]);

                        $args = ['import_id' => $import->id()];

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
                                        htmlspecialchars($urlHelper->fromRoute('admin/log/default', [], ['query' => ['job_id' => $job->getId()]]))
                                    ),
                                ]
                            );
                            $message->setEscapeHtml(false);
                            $this->messenger()->addSuccess($message);
                        } catch (\Exception $e) {
                            $this->messenger()->addError('Import start failed'); // @translate
                        }

                        return $this->redirect()->toRoute('admin/bulk');
                        break;
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

        $view = new ViewModel;
        $view->setVariable('importer', $importer);
        $view->setVariable('form', $form);
        if ($next === 'start') {
            $importArgs = [];
            $importArgs['reader'] = $session['reader'];
            $importArgs['processor'] = $currentForm === 'reader' ? [] : $session['processor'];
            // For security purpose.
            unset($importArgs['reader']['filename']);
            $view->setVariable('importArgs', $importArgs);
        }
        return $view;
    }

    protected function getStartFormsCallbacks(ImporterRepresentation $importer)
    {
        $controller = $this;
        $formsCallbacks = [];

        $reader = $importer->reader();
        if ($reader instanceof Parametrizable) {
            /* @return \Zend\Form\Form */
            $formsCallbacks['reader'] = function () use ($reader, $controller) {
                $readerForm = $controller->getForm($reader->getParamsFormClass());
                $readerConfig = $reader instanceof Configurable ? $reader->getConfig() : [];
                $readerForm->setData($readerConfig);

                $readerForm->add([
                    'name' => 'current_form',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'value' => 'reader',
                    ],
                ]);
                $readerForm->add([
                    'name' => 'reader_submit',
                    'type' => Fieldset::class,
                ]);
                $readerForm->get('reader_submit')->add([
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
            /* @return \Zend\Form\Form */
            $formsCallbacks['processor'] = function () use ($processor, $controller) {
                $processorForm = $controller->getForm($processor->getParamsFormClass(), [
                    'processor' => $processor,
                ]);
                $processorConfig = $processor instanceof Configurable ? $processor->getConfig() : [];
                $processorForm->setData($processorConfig);

                $processorForm->add([
                    'name' => 'current_form',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'value' => 'processor',
                    ],
                ]);
                $processorForm->add([
                    'name' => 'reader_submit',
                    'type' => Fieldset::class,
                ]);
                $processorForm->get('reader_submit')->add([
                    'name' => 'submit',
                    'type' => Element\Submit::class,
                    'attributes' => [
                        'value' => 'Continue', // @translate
                    ],
                ]);

                return $processorForm;
            };
        }

        /* @return \Zend\Form\Form */
        $formsCallbacks['start'] = function () use ($controller) {
            $startForm = $controller->getForm(ImporterStartForm::class);
            $startForm->add([
                'name' => 'current_form',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'value' => 'start',
                ],
            ]);
            return $startForm;
        };

        return $formsCallbacks;
    }
}
