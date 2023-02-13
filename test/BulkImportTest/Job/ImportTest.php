<?php declare(strict_types=1);
namespace BulkImportTest\Mvc\Controller\Plugin;

use BulkImport\Job\Import;
use BulkImportTest\Mock\Media\Ingester\MockUrl;
use Omeka\Entity\Job;
use Omeka\Stdlib\Message;
use OmekaTestHelper\Controller\OmekaControllerTestCase;

class ImportTest extends OmekaControllerTestCase
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Authentication\AuthenticationService;
     */
    protected $auth;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var string
     */
    protected $basepath;

    /**
     * @var \Omeka\Module\Manager
     */
    protected $moduleManager;

    /**
     * @var string
     */
    protected $tempfile;

    public function setUp(): void
    {
        parent::setup();

        $this->overrideConfig();

        $services = $this->getServiceLocator();
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->auth = $services->get('Omeka\AuthenticationService');
        $this->api = $services->get('Omeka\ApiManager');
        $this->basepath = dirname(__DIR__) . '/_files/';
        $this->moduleManager = $services->get('Omeka\ModuleManager');

        $this->loginAsAdmin();

        $this->tempfile = @tempnam(sys_get_temp_dir(), 'omk_bki_');
    }

    protected function overrideConfig(): void
    {
        require_once dirname(__DIR__) . '/Mock/Media/Ingester/MockUrl.php';

        $services = $this->getServiceLocator();

        $services->setAllowOverride(true);

        $downloader = $services->get('Omeka\File\Downloader');
        $validator = $services->get('Omeka\File\Validator');
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');

        $mediaIngesterManager = $services->get('Omeka\Media\Ingester\Manager');
        $mediaIngesterManager->setAllowOverride(true);
        $mockUrl = new MockUrl($downloader, $validator);
        $mockUrl->setTempFileFactory($tempFileFactory);
        $mediaIngesterManager->setService('url', $mockUrl);
        $mediaIngesterManager->setAllowOverride(false);
    }

    public function tearDown(): void
    {
        if (file_exists($this->tempfile)) {
            unlink($this->tempfile);
        }
    }

    /**
     * Reset index of the all resource tables to simplify addition of tests.
     */
    protected function resetResources(): void
    {
        $conn = $this->getServiceLocator()->get('Omeka\Connection');
        $sql = <<<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE item;
TRUNCATE TABLE item_set;
TRUNCATE TABLE item_item_set;
TRUNCATE TABLE media;
TRUNCATE TABLE resource;
TRUNCATE TABLE value;
TRUNCATE TABLE bulk_import;
TRUNCATE TABLE bulk_importer;
SET FOREIGN_KEY_CHECKS = 1;
SQL;
        $conn->exec($sql);
        $this->entityManager->clear();
    }

    public function sourceProvider()
    {
        return [
            ['spreadsheet/test.csv', ['items' => 3, 'media' => 4]],
            ['spreadsheet/test_empty_rows.csv', ['items' => 3]],
            ['spreadsheet/test_many_rows_html.tsv', ['items' => 30]],
            ['spreadsheet/test_many_rows_url.tsv', ['items' => 30]],
            ['spreadsheet/test_media_order.tsv', ['media' => 3], false, true],
            ['spreadsheet/test_media_order_add.tsv', ['media' => 4], false],
            ['spreadsheet/test_media_order_add_no_item.tsv', ['media' => 4]],
            ['spreadsheet/test_resources.tsv', ['item_sets' => 1, 'items' => 3, 'media' => 3], false],
            ['spreadsheet/test_resources_update.tsv', ['item_sets' => 1, 'items' => 3, 'media' => 4]],
            ['spreadsheet/test_resources_heritage.ods', ['item_sets' => 2, 'items' => 15, 'media' => 23]],
            ['spreadsheet/test_uri_label.tsv', ['items' => 3, 'media' => 4]],
        ];
    }

    /**
     * @dataProvider sourceProvider
     */
    public function testPerformCreate($filepath, $totals, $resetResources = true, $createItem = false): void
    {
        $filepath = $this->basepath . $filepath;
        $filebase = substr($filepath, 0, -4);

        if ($createItem) {
            $this->api->create('items')->getContent();
        }

        $this->performProcessForFile($filepath);

        foreach ($totals as $resourceName => $total) {
            $result = $this->api->search($resourceName)->getContent();
            $this->assertEquals($total, count($result));
            foreach ($result as $key => $resource) {
                $expectedFile = $filebase . '.' . $resourceName . '-' . ($key + 1) . '.api.json';
                if (!file_exists($expectedFile)) {
                    continue;
                }
                $expected = file_get_contents($expectedFile);
                $expected = $this->cleanApiResult(json_decode($expected, true));
                $resource = $this->cleanApiResult($resource->getJsonLd());
                $this->assertNotEmpty($resource);
                $this->assertEquals($expected, $resource);
            }
        }

        if ($resetResources) {
            $this->resetResources();
        }
    }

    /**
     * This false test allows to prepare a list of resources and to use them in
     * dependencies for performance reasons.
     *
     * @return array
     */
    public function testPerformCreateOne()
    {
        // Create items and media.
        $filepath = 'spreadsheet/test.csv';
        $filepath = $this->basepath . $filepath;
        $this->performProcessForFile($filepath);

        // Create item sets.
        $filepath = 'spreadsheet/test_update_g_replace.tsv';
        $filepath = $this->basepath . $filepath;
        $this->performProcessForFile($filepath);

        $resources = [];
        $totals = ['item_sets' => 3, 'items' => 3, 'media' => 4];
        foreach ($totals as $resourceName => $total) {
            $response = $this->api->search($resourceName);
            $this->assertEquals($total, $response->getTotalResults(), 'Resource type: ' . $resourceName);
            $result = $response->getContent();
            foreach ($result as $key => $resource) {
                $resources[$resourceName][$key + 1] = $resource;
            }
        }
        return $resources;
    }

    public function sourceUpdateProvider()
    {
        return [
            ['spreadsheet/test_skip.tsv', ['items', 1]],
            ['spreadsheet/test_update_a_append.tsv', ['items', 1]],
            ['spreadsheet/test_update_b_revise.tsv', ['items', 1]],
            ['spreadsheet/test_update_c_revise.tsv', ['items', 1]],
            ['spreadsheet/test_update_d_update.tsv', ['items', 1]],
            ['spreadsheet/test_update_e_replace.tsv', ['items', 1]],
            ['spreadsheet/test_update_f_replace.tsv', ['items', 1]],
            ['spreadsheet/test_update_g_replace.tsv', ['item_sets', 1]],
            ['spreadsheet/test_update_h_replace.tsv', ['items', 1]],
            ['spreadsheet/test_update_i_append.tsv', ['items', 1]],
            ['spreadsheet/test_update_j_revise.tsv', ['items', 1]],
            ['spreadsheet/test_update_k_revise.tsv', ['items', 1]],
            ['spreadsheet/test_update_l_update.tsv', ['items', 1]],
            ['spreadsheet/test_update_m_update.tsv', ['items', 1]],
        ];
    }

    /**
     * Tests are cumulative.
     *
     * @dataProvider sourceUpdateProvider
     * @depends testPerformCreateOne
     */
    public function testPerformUpdate($filepath, $options, $resources): void
    {
        $filepath = $this->basepath . $filepath;
        $filebase = substr($filepath, 0, -4);
        list($resourceName, $index) = $options;

        $resource = $resources[$resourceName][$index];
        $resourceId = $resource->id();
        $resource = $this->api->read($resourceName, $resourceId)->getContent();
        $this->assertNotEmpty($resource);

        $this->performProcessForFile($filepath);

        $resource = $this->api->search($resourceName, ['id' => $resourceId])->getContent();
        $this->assertNotEmpty($resource);

        $resource = reset($resource);

        $expectedFile = $filebase . '.' . $resourceName . '-' . ($index) . '.api.json';
        if (!file_exists($expectedFile)) {
            return;
        }
        $expected = file_get_contents($expectedFile);
        $expected = $this->cleanApiResult(json_decode($expected, true));
        $resource = $this->cleanApiResult($resource->getJsonLd());
        $this->assertNotEmpty($resource);
        $this->assertEquals($expected, $resource);
    }

    public function sourceDeleteProvider()
    {
        return [
            ['spreadsheet/test_delete_items.tsv', ['items', 2]],
            ['spreadsheet/test_delete_media.tsv', ['media', 4]],
        ];
    }

    /**
     * This test depends on other ones only to avoid check on removed resources.
     *
     * @dataProvider sourceDeleteProvider
     * @depends testPerformCreateOne
     * @depends testPerformUpdate
     */
    public function testPerformDelete($filepath, $options, $resources): void
    {
        // TODO Remove dependencies of testPerformDelete()

        $filepath = $this->basepath . $filepath;
        // $filebase = substr($filepath, 0, -4);
        list($resourceName, $index) = $options;

        $resource = $resources[$resourceName][$index];
        $resourceId = $resource->id();
        $resource = $this->api->read($resourceName, $resourceId)->getContent();
        $this->assertNotEmpty($resource, sprintf('Error before testing deletion: %s #%d does not exist.', $resourceName, $resourceId));

        $this->performProcessForFile($filepath);

        $resource = $this->api->search($resourceName, ['id' => $resourceId])->getContent();
        $this->assertEmpty($resource);
    }

    /**
     * Quick simple way to check import of url.
     *
     * @param string $filepath
     * @param string $basePathColumn
     * @return string
     */
    protected function addBasePath($filepath, $basePathColumn)
    {
        copy($filepath, $this->tempfile);
    }

    /**
     * Process the import of a file.
     *
     * @param string $filepath
     * @return \Omeka\Entity\Job
     */
    protected function performProcessForFile($filepath)
    {
        copy($filepath, $this->tempfile);

        $filebase = substr($filepath, 0, -4);
        $argspath = $filebase . '.args.json';
        if (!file_exists($argspath)) {
            $this->markTestSkipped(new Message('No argument files (%s).', basename($argspath))); // @translate
        }
        $args = json_decode(file_get_contents($filebase . '.args.json'), true);

        $argsImporter = empty($args['importer']) ? $this->importerArgs() : $args['importer'];
        $argsImport = empty($args['import']) ? $this->importArgs() : $args['import'];

        $argsImport['reader_params']['filename'] = $this->tempfile;

        $importer = new \BulkImport\Entity\Importer;
        $importer
            ->setOwner($this->auth->getIdentity())
            ->setLabel($argsImporter['label'])
            ->setReaderClass($argsImporter['reader_class'])
            ->setReaderConfig($argsImporter['reader_config'])
            ->setProcessorClass($argsImporter['processor_class'])
            ->setProcessorConfig($argsImporter['processor_config']);
        $this->entityManager->persist($importer);

        $import = new \BulkImport\Entity\Import;
        $import
            ->setImporter($importer)
            ->setReaderParams($argsImport['reader_params'])
            ->setProcessorParams($argsImport['processor_params']);
        $this->entityManager->persist($import);
        $this->entityManager->flush();

        $args = ['bulk_import_id' => $import->getId()];

        $job = new Job;
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass(Import::class);
        $job->setArgs($args);
        $job->setOwner($this->auth->getIdentity());
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $import = new Import($job, $this->getServiceLocator());
        $import->perform();

        return $job;
    }

    protected function cleanApiResult(array $resource)
    {
        // Make the representation a pure array.
        // TODO Check if this is still useful for linked resource or annotations with events.
        $resource = json_decode(json_encode($resource), true);

        unset($resource['@context']);
        unset($resource['@type']);
        unset($resource['@id']);
        unset($resource['o:id']);
        unset($resource['o:created']);
        unset($resource['o:modified']);
        unset($resource['o:owner']['@id']);
        unset($resource['o:resource_template']['@id']);
        unset($resource['o:resource_class']['@id']);
        unset($resource['o:items']['@id']);
        unset($resource['o:sha256']);
        if (isset($resource['o:item_set'])) {
            foreach ($resource['o:item_set'] as &$itemSet) {
                unset($itemSet['@id']);
            }
        }
        if (isset($resource['o:media'])) {
            foreach ($resource['o:media'] as &$media) {
                unset($media['@id']);
            }
        }
        if (isset($resource['o:item'])) {
            unset($resource['o:item']['@id']);
            unset($resource['o:filename']);
            unset($resource['o:original_url']);
            unset($resource['o:thumbnail_urls']);
        }

        if (!$this->hasModule('Mapping')) {
            unset($resource['o-module-mapping:lat']);
            unset($resource['o-module-mapping:lng']);
            unset($resource['o-module-mapping:bounds']);
            unset($resource['o-module-mapping:default_lat']);
            unset($resource['o-module-mapping:default_lng']);
            unset($resource['o-module-mapping:default_zoom']);
        }

        return $resource;
    }

    protected function hasModule($module)
    {
        $module = $this->moduleManager->getModule($module);
        return $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }

    protected function importerArgs()
    {
        return [
            'label' => 'Spreadsheet mixed',
            'reader_class' => \BulkImport\Reader\SpreadsheetReader::class,
            'reader_config' => [
                'delimiter' => ',',
                'enclosure' => '"',
                'escape' => '\\',
                'separator' => ',',
            ],
            'processor_class' => \BulkImport\Processor\ResourceProcessor::class,
            'processor_config' => [
                'o:resource_template' => '1',
                'o:resource_class' => '',
                'o:is_public' => 'true',
                'resource_name' => 'items',
                'o:item_set' => [],
                'o:item' => '',
            ],
        ];
    }

    protected function importArgs()
    {
        return [
            'reader_params' => [
                'separator' => '|',
                'filename' => '/tmp/omk_a',
                'file' => [
                    'name' => 'filename.tsv',
                    'type' => 'text/tab-separated-values',
                    'error' => 0,
                    'size' => 27482,
                ],
                'delimiter' => "\t",
                'enclosure' => chr(0),
                'escape' => chr(0),
            ],
            'processor_params' => [
                'o:resource_template' => '1',
                'o:resource_class' => '',
                'o:is_public' => 'true',
                'resource_name' => 'items',
                'o:item_set' => [],
                'o:item' => '',
                'mapping' => [
                ],
            ],
        ];
    }
}
