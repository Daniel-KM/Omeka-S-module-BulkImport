<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class SqlReaderConfigForm extends AbstractReaderConfigForm
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'database',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Database name', // @translate
                ],
                'attributes' => [
                    'id' => 'database',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'username',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Database username', // @translate
                    'info' => 'Even if the module does not write in the database, it is recommended to use a read-only user, in particular when the source is a living database.', // @translate
                ],
                'attributes' => [
                    'id' => 'username',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'password',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Database user password', // @translate
                    'info' => 'The password will be saved some seconds in the database, until the job starts.', // @translate
                ],
                'attributes' => [
                    'id' => 'password',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'hostname',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Database host name', // @translate
                ],
                'attributes' => [
                    'id' => 'hostname',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'port',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Database port', // @translate
                ],
                'attributes' => [
                    'id' => 'port',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'charset',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Character set', // @translate
                ],
                'attributes' => [
                    'id' => 'charset',
                    'required' => false,
                    'placeholder' => 'utf8',
                    // The default value is utf8 in most of the cases.
                    'value' => 'utf8',
                ],
            ])
            ->add([
                'name' => 'prefix',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Table prefix', // @translate
                ],
                'attributes' => [
                    'id' => 'prefix',
                    'required' => false,
                    'placeholder' => 'spip_',
                ],
            ])
        ;

        parent::init();

        $this->getInputFilter()
            ->add([
                'name' => 'port',
                'required' => false,
            ]);
    }
}
