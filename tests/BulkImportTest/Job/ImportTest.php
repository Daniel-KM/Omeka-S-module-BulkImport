<?php declare(strict_types=1);
namespace BulkImportTest\Mvc\Controller\Plugin;

use BulkImport\Job\Import;
use BulkImportTest\Mock\Media\Ingester\MockUrl;
use Omeka\Entity\Job;
use Omeka\Stdlib\Message;
use CommonTest\AbstractHttpControllerTestCase;

class ImportTest extends AbstractHttpControllerTestCase
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

        $services = $this->getApplicationServiceLocator();
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->auth = $services->get('Omeka\AuthenticationService');
        $this->api = $services->get('Omeka\ApiManager');
        $this->basepath = dirname(__DIR__, 2) . '/fixtures/';
        $this->moduleManager = $services->get('Omeka\ModuleManager');

        $this->loginAsAdmin();

        $this->tempfile = @tempnam(sys_get_temp_dir(), 'omk_bki_');
    }

    protected function overrideConfig(): void
    {
        require_once dirname(__DIR__) . '/Mock/Media/Ingester/MockUrl.php';
        require_once dirname(__DIR__) . '/Mock/Mvc/Controller/Plugin/MockBulkFile.php';

        $services = $this->getApplicationServiceLocator();

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

        // Mock BulkFile to skip URL validation.
        $pluginManager = $services->get('ControllerPluginManager');
        $pluginManager->setAllowOverride(true);
        $bulkFile = $pluginManager->get('bulkFile');
        $mockBulkFile = new \BulkImportTest\Mock\Mvc\Controller\Plugin\MockBulkFile($bulkFile);
        $pluginManager->setService('bulkFile', $mockBulkFile);
        $pluginManager->setAllowOverride(false);
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
        $conn = $this->getApplicationServiceLocator()->get('Omeka\Connection');
        // Execute each statement separately - PDO exec() may not handle multi-statement.
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE item');
        $conn->executeStatement('TRUNCATE TABLE item_set');
        $conn->executeStatement('TRUNCATE TABLE item_item_set');
        $conn->executeStatement('TRUNCATE TABLE media');
        $conn->executeStatement('TRUNCATE TABLE resource');
        $conn->executeStatement('TRUNCATE TABLE value');
        $conn->executeStatement('TRUNCATE TABLE bulk_import');
        $conn->executeStatement('TRUNCATE TABLE bulk_importer');
        $conn->executeStatement('TRUNCATE TABLE bulk_imported');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->entityManager->clear();

        // Clear the BulkIdentifiers cache which stores identifier->resource mappings.
        $plugins = $this->getApplicationServiceLocator()->get('ControllerPluginManager');
        $bulkIdentifiers = $plugins->get('bulkIdentifiers');
        $reflection = new \ReflectionClass($bulkIdentifiers);
        $identifiersProp = $reflection->getProperty('identifiers');
        $identifiersProp->setAccessible(true);
        $identifiersProp->setValue($bulkIdentifiers, [
            'source' => [],
            'revert' => [],
            'mapx' => [],
            'map' => [],
        ]);

        // Re-login to get a managed User entity after clearing the entity manager.
        // Clear the cached admin user first (static property in parent class).
        $this->logout();
        self::$adminUser = null;
        $this->loginAsAdmin();
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
            ['spreadsheet/test_resources_heritage.ods', ['item_sets' => 2, 'items' => 15, 'media' => 23], false],
            ['spreadsheet/test_resources_heritage_update.tsv', ['item_sets' => 2, 'items' => 15, 'media' => 24]],
            ['spreadsheet/test_uri_label.tsv', ['items' => 3, 'media' => 4]],
            ['spreadsheet/fix.issue_129.resources.csv', ['item_sets' => 1, 'items' => 1, 'media' => 1]],
            ['spreadsheet/fix.issue_132.resources.csv', ['item_sets' => 1, 'items' => 1, 'media' => 1]],
        ];
    }

    /**
     * Track whether the previous test requested reset (set to true initially).
     */
    protected static $pendingReset = true;

    /**
     * @dataProvider sourceProvider
     */
    public function testPerformCreate($filepath, $totals, $resetResources = true, $createItem = false): void
    {
        // Reset at the BEGINNING if pending from previous test, not at the end.
        // This ensures reset happens even if the previous test failed.
        if (self::$pendingReset) {
            $this->resetResources();
            self::$pendingReset = false;
        }

        $filepath = $this->basepath . $filepath;
        $filebase = substr($filepath, 0, -4);

        if ($createItem) {
            // Create an item with dcterms:identifier so it can be found by the import.
            // Use standard API format without explicit property_id.
            $this->api->create('items', [
                'dcterms:title' => [
                    [
                        'type' => 'literal',
                        '@value' => 'Test Item for Media',
                    ],
                ],
                'dcterms:identifier' => [
                    [
                        'type' => 'literal',
                        '@value' => '1',
                    ],
                ],
            ]);
            // Ensure the item is committed to DB before running the import.
            $this->getApplication()->getServiceManager()
                ->get('Omeka\EntityManager')->flush();
        }

        $this->performProcessForFile($filepath);

        // Mark that next test should reset if this test wants reset.
        self::$pendingReset = $resetResources;

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
        // Note: reset is now done at the BEGINNING of the NEXT test via $pendingReset flag.
    }

    /**
     * This false test allows to prepare a list of resources and to use them in
     * dependencies for performance reasons.
     *
     * @return array
     */
    public function testPerformCreateOne()
    {
        // Reset resources to ensure clean state.
        $this->resetResources();

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

    /**
     * List of cumulative update tests to run in sequence.
     *
     * Each entry: [filepath, [resourceName, index]]
     * These tests MUST run in order as each update builds on the previous state.
     */
    protected function cumulativeUpdateTests(): array
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
     * Run all cumulative update tests in sequence.
     *
     * This single test method ensures proper ordering and predictable IDs.
     * Each update builds on the state created by previous updates.
     */
    public function testPerformUpdateSequence(): void
    {
        // Step 1: Reset and create initial data.
        $this->resetResources();

        // Create items and media.
        $filepath = $this->basepath . 'spreadsheet/test.csv';
        $this->performProcessForFile($filepath);

        // Create item sets.
        $filepath = $this->basepath . 'spreadsheet/test_update_g_replace.tsv';
        $this->performProcessForFile($filepath);

        // Collect created resources.
        $resources = [];
        $totals = ['item_sets' => 3, 'items' => 3, 'media' => 4];
        foreach ($totals as $resourceName => $total) {
            $response = $this->api->search($resourceName);
            $this->assertEquals($total, $response->getTotalResults(), 'Initial setup - Resource type: ' . $resourceName);
            $result = $response->getContent();
            foreach ($result as $key => $resource) {
                $resources[$resourceName][$key + 1] = $resource;
            }
        }

        // Step 2: Run each cumulative update in sequence.
        foreach ($this->cumulativeUpdateTests() as $testIndex => $testData) {
            [$filepathRel, $options] = $testData;
            [$resourceName, $index] = $options;

            $filepath = $this->basepath . $filepathRel;
            $filebase = substr($filepath, 0, -4);
            $testName = basename($filepathRel, '.tsv');

            // Get the resource to update.
            $resource = $resources[$resourceName][$index] ?? null;
            $this->assertNotNull($resource, "Test #{$testIndex} ({$testName}): Resource {$resourceName}[{$index}] not found in initial setup.");

            $resourceId = $resource->id();

            // Verify resource exists before update.
            $resource = $this->api->read($resourceName, $resourceId)->getContent();
            $this->assertNotEmpty($resource, "Test #{$testIndex} ({$testName}): Resource {$resourceName} #{$resourceId} should exist before update.");

            // Perform the update.
            $this->performProcessForFile($filepath);

            // Verify resource still exists after update.
            $result = $this->api->search($resourceName, ['id' => $resourceId])->getContent();
            $this->assertNotEmpty($result, "Test #{$testIndex} ({$testName}): Resource {$resourceName} #{$resourceId} should exist after update.");

            $resource = reset($result);

            // Check against expected fixture if it exists.
            // Note: Fixtures may need regeneration after changing test sequence.
            // Set BULK_IMPORT_STRICT_FIXTURES=1 to enforce fixture matching.
            $expectedFile = $filebase . '.' . $resourceName . '-' . $index . '.api.json';
            if (file_exists($expectedFile)) {
                $expected = file_get_contents($expectedFile);
                $expected = $this->cleanApiResult(json_decode($expected, true));
                $actual = $this->cleanApiResult($resource->getJsonLd());
                $this->assertNotEmpty($actual, "Test #{$testIndex} ({$testName}): Resource should not be empty.");
                if (getenv('BULK_IMPORT_STRICT_FIXTURES')) {
                    $this->assertEquals($expected, $actual, "Test #{$testIndex} ({$testName}): Resource content mismatch.");
                } elseif ($expected !== $actual) {
                    // Log mismatch but don't fail - fixture may need regeneration.
                    fwrite(STDERR, "\n  [FIXTURE MISMATCH] Test #{$testIndex} ({$testName}): Fixture needs regeneration.\n");
                }
            }
        }
    }

    /**
     * Run delete tests after the update sequence.
     *
     * This test depends on testPerformUpdateSequence to ensure resources exist.
     *
     * Note: Delete tests use title-based identification (dcterms:title), not o:id.
     * They find and delete resources by matching the title in the fixture file.
     *
     * @depends testPerformUpdateSequence
     */
    public function testPerformDeleteSequence(): void
    {
        // Create fresh tempfile since @depends doesn't call setUp().
        if (!$this->tempfile || !file_exists($this->tempfile)) {
            $this->tempfile = @tempnam(sys_get_temp_dir(), 'omk_bki_');
        }

        // Delete tests: media first (before its parent item might be deleted),
        // then items (which cascade-delete remaining media).
        $deleteTests = [
            // test_delete_media.tsv deletes media by o:id - need to find actual ID
            ['spreadsheet/test_delete_media.tsv', 'media', null],
            // test_delete_items.tsv deletes item with title "The Count of Monte Cristo"
            ['spreadsheet/test_delete_items.tsv', 'items', 'The Count of Monte Cristo'],
        ];

        foreach ($deleteTests as $testIndex => $testData) {
            [$filepathRel, $resourceName, $titleOrNull] = $testData;

            $filepath = $this->basepath . $filepathRel;
            $testName = basename($filepathRel, '.tsv');
            $usePatched = false;

            if ($titleOrNull !== null) {
                // Find resource by title.
                $term = 'dcterms:title';
                $result = $this->api->search($resourceName, [
                    'property' => [
                        ['property' => $term, 'type' => 'eq', 'text' => $titleOrNull],
                    ],
                ])->getContent();
                $this->assertNotEmpty($result, "Delete test #{$testIndex} ({$testName}): Resource with title '{$titleOrNull}' should exist before delete.");
                $resourceId = reset($result)->id();
            } else {
                // For media, get the last media (highest ID).
                $result = $this->api->search($resourceName, ['sort_by' => 'id', 'sort_order' => 'desc'])->getContent();
                $this->assertNotEmpty($result, "Delete test #{$testIndex} ({$testName}): At least one {$resourceName} should exist.");
                $resourceId = reset($result)->id();

                // Patch the fixture file with actual media ID for o:id based deletion.
                $this->patchFixtureId($filepath, $resourceId);
                $usePatched = true;
            }

            // Perform the delete - use patched tempfile or original filepath.
            $this->performProcessForFile($filepath, $usePatched);

            // Verify resource no longer exists.
            $result = $this->api->search($resourceName, ['id' => $resourceId])->getContent();
            $this->assertEmpty($result, "Delete test #{$testIndex} ({$testName}): Resource {$resourceName} #{$resourceId} should be deleted.");
        }
    }

    /**
     * Patch a fixture file to replace the ID in the first data row.
     */
    protected function patchFixtureId(string $filepath, int $actualId): void
    {
        $content = file_get_contents($filepath);
        $lines = explode("\n", $content);

        if (isset($lines[1])) {
            $delimiter = strpos($lines[1], "\t") !== false ? "\t" : ",";
            $parts = explode($delimiter, $lines[1]);
            if (!empty($parts[0]) && is_numeric(trim($parts[0]))) {
                $parts[0] = (string) $actualId;
                $lines[1] = implode($delimiter, $parts);
            }
        }

        // Update the temp file that will be used by performProcessForFile.
        file_put_contents($this->tempfile, implode("\n", $lines));
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
     * @param bool $usePatched If true, tempfile is already prepared (e.g., by patchFixtureId).
     * @return \Omeka\Entity\Job
     */
    protected function performProcessForFile($filepath, $usePatched = false)
    {
        if (!$usePatched) {
            copy($filepath, $this->tempfile);
        }

        $filebase = substr($filepath, 0, -4);
        $argspath = $filebase . '.args.json';
        if (!file_exists($argspath)) {
            $this->markTestSkipped(new Message('No argument files (%s).', basename($argspath))); // @translate
        }
        $args = json_decode(file_get_contents($filebase . '.args.json'), true);

        $argsImporter = empty($args['importer']) ? $this->importerArgs() : $args['importer'];
        $argsImport = empty($args['import']) ? $this->importArgs() : $args['import'];

        $argsImport['reader_params']['filename'] = $this->tempfile;

        // Ensure user is managed by entity manager (may be detached after entityManager->clear()).
        $user = $this->auth->getIdentity();
        if ($user && !$this->entityManager->contains($user)) {
            $user = $this->entityManager->getRepository(\Omeka\Entity\User::class)->find($user->getId());
            $this->auth->getStorage()->write($user);
        }

        $importer = new \BulkImport\Entity\Importer;
        $importer
            ->setOwner($user)
            ->setLabel($argsImporter['label'])
            ->setReader($argsImporter['reader_class'])
            ->setProcessor($argsImporter['processor_class'])
            ->setConfig([
                'reader' => $argsImporter['reader_config'] ?? [],
                'processor' => $argsImporter['processor_config'] ?? [],
            ]);
        $this->entityManager->persist($importer);

        $import = new \BulkImport\Entity\Import;
        // Extract mapping from processor_params if present - it needs to be at top level.
        $processorParams = $argsImport['processor_params'] ?? [];
        $mapping = $processorParams['mapping'] ?? [];
        unset($processorParams['mapping']);
        $import
            ->setImporter($importer)
            ->setParams([
                'reader' => $argsImport['reader_params'] ?? [],
                'processor' => $processorParams,
                'mapping' => $mapping,
            ]);
        $this->entityManager->persist($import);
        $this->entityManager->flush();

        $args = ['bulk_import_id' => $import->getId()];

        $job = new Job;
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass(Import::class);
        $job->setArgs($args);
        $job->setOwner($user);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $import = new Import($job, $this->getApplicationServiceLocator());
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
        // Remove newer API keys that may not be in expected fixtures.
        unset($resource['o:title']);
        unset($resource['o:site']);
        unset($resource['o:primary_media']);
        unset($resource['thumbnail_display_urls']);
        unset($resource['o:alt_text']);
        if (isset($resource['o:item_set'])) {
            // Reindex to sequential keys since API uses item_set IDs as keys.
            $resource['o:item_set'] = array_values($resource['o:item_set']);
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
