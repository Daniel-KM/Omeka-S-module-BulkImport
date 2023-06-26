<?php declare(strict_types=1);

namespace BulkImport\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class MappingForm extends Form
{
    public function init(): void
    {
        parent::init();

        $defaultMapping = <<<'XML'
<mapping>
    <map>
        <from xpath="/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a']"/>
        <to field="dcterms:title" datatype="literal" language="" visibility=""/>
        <mod raw="" prepend="" pattern="" append=""/>
    </map>
</mapping>

XML;

        $this
            ->setAttribute('id', 'bulk-importer-mapping')
            ->add([
                'name' => 'o:label',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Label', // @translate
                ],
                'attributes' => [
                    'id' => 'o-label',
                    'required' => true,
                ],
            ])

            ->add([
                'name' => 'o-bulk:mapping',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Mapping', // @translate
                ],
                'attributes' => [
                    'id' => 'o-bulk-mapping',
                    'rows' => 30,
                    'class' => 'codemirror-code',
                    'placeholder' => $defaultMapping,
                    'value' => $defaultMapping,
                ],
            ])

            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Save', // @translate
                ],
            ]);
    }
}
