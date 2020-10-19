<?php declare(strict_types=1);
namespace BulkImportTest\View\Helper;

use BulkImport\View\Helper\AutomapFields;
use Omeka\Test\AbstractHttpControllerTestCase;

class AutomapFieldsTest extends AbstractHttpControllerTestCase
{
    protected $automapFields;

    public function setUp(): void
    {
        parent::setup();

        $services = $this->getApplication()->getServiceManager();

        // Copy of the factory of the helper.
        $filepath = '/data/mappings/fields_to_metadata.php';
        $map = require dirname(__DIR__, 4) . $filepath;
        $viewHelpers = $services->get('ViewHelperManager');
        $this->automapFields = new AutomapFields(
            $map,
            $viewHelpers->get('api'),
            $viewHelpers->get('translate')
        );
    }

    public function testInvoke(): void
    {
        $automapFields = $this->automapFields;
        $fields = [];
        $options = [];
        $this->assertEquals([], $automapFields->__invoke($fields, $options));
    }

    public function sourceProvider()
    {
        return [
            [['title' => 'dcterms:title']],
            [['foo' => null]],
            [['dcterms:' => null]],
            [["   \n    titlE  \t" => 'dcterms:title']],
            [[
                'title' => 'dcterms:title',
                'Title' => 'dcterms:title',
            ]],
            [['Internal id' => 'o:id']],
            [['item identifier' => 'o:item[dcterms:identifier]']],
            [['Dublin Core : relation' => 'dcterms:relation']],
            [['media title' => 'o:media[dcterms:title]']],
            [['media url' => 'url']],
        ];
    }

    /**
     * @dataProvider sourceProvider
     */
    public function testInvokes($fieldsToMetadata, $options = []): void
    {
        $automapFields = $this->automapFields;
        $fields = array_keys($fieldsToMetadata);
        $metadata = array_values($fieldsToMetadata);
        $this->assertEquals($metadata, $automapFields($fields, $options));
    }

    public function sourceProviderFullMatch()
    {
        return [
            [['Dublin Core : Title' => ['field' => 'dcterms:title', '@language' => null, 'type' => null]]],
            [['Dublin Core : Title @fr' => ['field' => 'dcterms:title', '@language' => 'fr', 'type' => null]]],
            [['Dublin Core : Title @fr-FR' => ['field' => 'dcterms:title', '@language' => 'fr-FR', 'type' => null]]],
            [['dcterms:title@fr-FR' => ['field' => 'dcterms:title', '@language' => 'fr-FR', 'type' => null]]],
            [['dcterms:title@fr-FR-2' => null]],
            [['Dublin Core : Title @fr-FR ^^resource:item' => ['field' => 'dcterms:title', '@language' => 'fr-FR', 'type' => 'resource:item']]],
            [['Title @fr-FR ^^resource:item' => ['field' => 'dcterms:title', '@language' => 'fr-FR', 'type' => 'resource:item']]],
            [['Dublin Core : Title ^^ resource:item' => ['field' => 'dcterms:title', '@language' => null, 'type' => 'resource:item']]],
            [['Dublin Core : Title ^^ foo' => ['field' => 'dcterms:title', '@language' => null, 'type' => 'foo']]],
        ];
    }

    /**
     * @dataProvider sourceProviderFullMatch
     */
    public function testInvokesFullMatch($fieldsToMetadata, $options = []): void
    {
        $automapFields = $this->automapFields;
        $options = ['output_full_matches' => true] + $options;
        $fields = array_keys($fieldsToMetadata);
        $metadata = array_values($fieldsToMetadata);
        $this->assertEquals($metadata, $automapFields($fields, $options));
    }
}
