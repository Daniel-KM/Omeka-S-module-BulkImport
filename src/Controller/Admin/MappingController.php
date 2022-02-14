<?php declare(strict_types=1);

namespace BulkImport\Controller\Admin;

use BulkImport\Form\MappingDeleteForm;
use BulkImport\Form\MappingForm;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Model\ViewModel;
use Log\Stdlib\PsrMessage;

class MappingController extends AbstractActionController
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
        return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping', 'action' => 'browse']);
    }

    public function browseAction()
    {
        $this->setBrowseDefaults('label', 'asc');

        $response = $this->api()->search('bulk_mappings', ['sort_by' => 'label', 'sort_order' => 'asc']);
        $mappings = $response->getContent();

        return new ViewModel([
            'mappings' => $mappings,
            'resources' => $mappings,
        ]);
    }

    public function addAction()
    {
        return $this->editAction();
    }

    public function editAction()
    {
        $id = (int) $this->params()->fromRoute('id');
        /** @var \BulkImport\Api\Representation\MappingRepresentation $entity */
        $entity = ($id) ? $this->api()->searchOne('bulk_mappings', ['id' => $id])->getContent() : null;

        if ($id && !$entity) {
            $message = new PsrMessage('Mapping #{mapping_id} does not exist', ['mapping_id' => $id]); // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping']);
        }

        $form = $this->getForm(MappingForm::class);
        if ($entity) {
            $data = $entity->getJsonLd();
            $form->setData($data);
        }

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                if ($entity) {
                    $response = $this->api($form)->update('bulk_mappings', $this->params('id'), $data, [], ['isPartial' => true]);
                } else {
                    $data['o:owner'] = $this->identity();
                    $response = $this->api($form)->create('bulk_mappings', $data);
                }

                if (!$response) {
                    $this->messenger()->addError('Save of mapping failed'); // @translate
                    return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping'], true);
                } else {
                    $this->messenger()->addSuccess('Mapping successfully saved'); // @translate
                    return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping', 'action' => 'browse'], true);
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'form' => $form,
        ]);
    }

    public function deleteAction()
    {
        $id = (int) $this->params()->fromRoute('id');
        $entity = ($id) ? $this->api()->searchOne('bulk_mappings', ['id' => $id])->getContent() : null;

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
            'entity' => $entity,
            'form' => $form,
        ]);
    }
}
