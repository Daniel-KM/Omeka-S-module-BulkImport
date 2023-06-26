<?php declare(strict_types=1);

namespace BulkImport\Controller\Admin;

use BulkImport\Form\MappingDeleteForm;
use BulkImport\Form\MappingForm;
use BulkImport\Reader\MappingsTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Model\ViewModel;
use Log\Stdlib\PsrMessage;

class MappingController extends AbstractActionController
{
    use ServiceLocatorAwareTrait;
    use MappingsTrait;

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
    }

    public function indexAction()
    {
        return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping', 'action' => 'browse']);
    }

    public function browseAction()
    {
        $this->setBrowseDefaults('label', 'asc');

        $response = $this->api()->search('bulk_mappings', ['sort_by' => 'label', 'sort_order' => 'asc']);
        $mappings = $response->getContent();

        return new ViewModel([
            'bulkMappings' => $mappings,
            'resources' => $mappings,
            'internalMappings' => $this->getInternalBulkMappings(),
        ]);
    }

    public function showAction()
    {
        $entity = $this->getBulkMapping();
        $isBulkMapping = is_object($entity) && $entity instanceof \BulkImport\Api\Representation\MappingRepresentation;
        $isInternalMapping = is_string($entity);
        if (!$isBulkMapping && !$isInternalMapping) {
            return $entity;
        }

        if ($isBulkMapping) {
            return new ViewModel([
                'isBulkMapping' => true,
                'bulkMapping' => $entity,
                'resource' => $entity,
                'label' => $entity->label(),
                'content' => $entity->mapping(),
            ]);
        }

        return new ViewModel([
            'isBulkMapping' => false,
            'bulkMapping' => null,
            'resource' => null,
            'label' => $this->getInternalBulkMappings()[$entity],
            'content' => $this->getMapping($entity),
        ]);
    }

    public function addAction()
    {
        return $this->edit('add');
    }

    public function copyAction()
    {
        return $this->edit('copy');
    }

    public function editAction()
    {
        return $this->edit('edit');
    }

    protected function edit(string $action)
    {
        if ($action === 'add') {
            $entity = null;
        } else {
            $entity = $this->getBulkMapping();
            $isBulkMapping = is_object($entity) && $entity instanceof \BulkImport\Api\Representation\MappingRepresentation;
            $isInternalMapping = is_string($entity);
            if (!$isBulkMapping && !$isInternalMapping) {
                return $entity;
            }
        }

        /** @var \BulkImport\Form\MappingForm $form */
        $form = $this->getForm(MappingForm::class);
        if ($entity) {
            if ($isInternalMapping) {
                $label = $this->getInternalBulkMappings()[$entity];
                if ($action === 'copy') {
                    $label = sprintf($this->translate('%s (copy)'), str_ireplace( // @translate
                        ['.jsondot', '.jsonpath', '.jmespath', '.ini', '.xslt1', '.xslt2', '.xslt3', '.xslt', '.xsl', '.xml'], '', $label
                    ));
                }
                $form->setData([
                    'o:label' => $label,
                    'o-bulk:mapping' => $this->getMapping($entity),
                ]);
            } else {
                $data = $entity->getJsonLd();
                if ($action === 'copy') {
                    $data['o:label'] = sprintf($this->translate('%s (copy)'), $data['o:label']); // @translate
                }
                $form->setData($data);
            }
        }

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                if ($isBulkMapping && $entity && $action === 'edit') {
                    $response = $this->api($form)->update('bulk_mappings', $this->params('id'), $data, [], ['isPartial' => true]);
                } else {
                    $data['o:owner'] = $this->identity();
                    $response = $this->api($form)->create('bulk_mappings', $data);
                }
                if ($response) {
                    $this->messenger()->addSuccess('Mapping successfully saved'); // @translate
                    return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping', 'action' => 'browse'], true);
                }
                $this->messenger()->addError('Save of mapping failed'); // @translate
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $viewModel = new ViewModel([
            'form' => $form,
            'bulkMapping' => $entity,
            'resource' => $entity,
        ]);
        if ($action === 'copy') {
            $viewModel->setTemplate('bulk/admin/mapping/add');
        }
        return $viewModel;
    }

    public function deleteAction()
    {
        $id = (int) $this->params()->fromRoute('id');
        $entity = $id ? $this->api()->searchOne('bulk_mappings', ['id' => $id])->getContent() : null;

        if (!$entity) {
            $message = new PsrMessage('Mapping #{mapping_id} does not exist', ['mapping_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping']);
        }

        // TODO Add a check to indicate if the mapping is used. See importer.

        $form = $this->getForm(MappingDeleteForm::class);
        $form->setData($entity->getJsonLd());

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $response = $this->api($form)->delete('bulk_mappings', $id);
                if ($response) {
                    $this->messenger()->addSuccess('Mapping successfully deleted'); // @translate
                } else {
                    $this->messenger()->addError('Delete of mapping failed'); // @translate
                }
                return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping']);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'resource' => $entity,
            'bulkMapping' => $entity,
            'form' => $form,
        ]);
    }

    /**
     * Get current bulk mapping.
     *
     * The bulk mapping may be an object or an id for internal mapping.
     *
     * @return \BulkImport\Api\Representation\MappingRepresentation|string|\Laminas\Http\Response
     */
    protected function getBulkMapping()
    {
        $id = ((int) $this->params()->fromRoute('id'))
            ?: $this->params()->fromQuery('id');

        if (!$id) {
            $message = new PsrMessage('No mapping id set.'); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping']);
        }

        if (is_numeric($id)) {
            /** @var \BulkImport\Api\Representation\MappingRepresentation|string $entity */
            $entity = $this->api()->read('bulk_mappings', ['id' => $id])->getContent();
        } else {
            $internalMappings = $this->getInternalBulkMappings();
            $entity = isset($internalMappings[$id]) ? $id : null;
        }

        if (!$entity) {
            $message = new PsrMessage('Mapping #{mapping_id} does not exist', ['mapping_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping']);
        }

        return $entity;
    }
}
