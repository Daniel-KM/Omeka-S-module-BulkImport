<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use Laminas\Form\Element;

trait TaskTrait
{
    protected function addTask(): \Laminas\Form\Form
    {
        $this
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

    protected function addTaskInputFilter(): \Laminas\Form\Form
    {
        $this->getInputFilter()
            ->add([
                'name' => 'store_as_task',
                'required' => false,
            ]);
        return $this;
    }
}
