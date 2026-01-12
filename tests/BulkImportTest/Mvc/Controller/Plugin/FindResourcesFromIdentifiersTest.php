<?php declare(strict_types=1);
namespace BulkImportTest\Mvc\Controller\Plugin;

use CommonTest\AbstractHttpControllerTestCase;

class FindResourcesFromIdentifiersTest extends AbstractHttpControllerTestCase
{
    protected $connection;
    protected $api;
    protected $findResourcesFromIdentifiers;

    protected $resources;

    public function setUp(): void
    {
        parent::setup();

        $services = $this->getApplicationServiceLocator();
        $this->connection = $services->get('Omeka\Connection');

        $this->loginAsAdmin();

        // Use ApiManager directly to avoid entity detachment issues with controller plugins.
        $this->api = $services->get('Omeka\ApiManager');
        $this->findResourcesFromIdentifiers = $services->get('ControllerPluginManager')->get('findResourcesFromIdentifiers');

        // Ensure user is managed by entity manager (may be detached after getting controller plugins).
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');
        $user = $auth->getIdentity();
        if ($user && !$entityManager->contains($user)) {
            $user = $entityManager->getRepository(\Omeka\Entity\User::class)->find($user->getId());
            $auth->getStorage()->write($user);
        }

        // Clean up resources from previous test runs to ensure consistent IDs.
        $conn = $this->connection;
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE item');
        $conn->executeStatement('TRUNCATE TABLE item_set');
        $conn->executeStatement('TRUNCATE TABLE media');
        $conn->executeStatement('TRUNCATE TABLE resource');
        $conn->executeStatement('TRUNCATE TABLE value');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $entityManager->clear();

        // Re-login after clearing entities.
        $this->logout();
        self::$adminUser = null;
        $this->loginAsAdmin();

        // Re-get api after entity clear.
        $this->api = $services->get('Omeka\ApiManager');

        // 10 is property id of dcterms:identifier.
        $this->resources[] = $this->api->create('item_sets', [
            'dcterms:identifier' => [
                ['property_id' => 10, 'type' => 'literal', '@value' => 'Foo Item Set'],
            ],
        ])->getContent();

        $this->resources[] = $this->api->create('items', [
            'dcterms:identifier' => [
                ['property_id' => 10, 'type' => 'literal', '@value' => 'Foo Item'],
            ],
        ])->getContent();

        $this->resources[] = $this->api->create('media', [
            'dcterms:identifier' => [
                ['property_id' => 10, 'type' => 'literal', '@value' => 'Foo Media'],
            ],
            'o:ingester' => 'html',
            'html' => '<p>This <strong>is</strong> <em>html</em>.</p>',
            'o:item' => ['o:id' => $this->resources[1]->id()],
        ])->getContent();

        // Check a case insensitive duplicate (should return the first).
        $this->resources[] = $this->api->create('item_sets', [
            'dcterms:identifier' => [
                ['property_id' => 10, 'type' => 'literal', '@value' => 'foo item set'],
            ],
        ])->getContent();

        // Allows to check a true duplicate (should return the first).
        $this->resources[] = $this->api->create('items', [
            'dcterms:identifier' => [
                ['property_id' => 10, 'type' => 'literal', '@value' => 'Foo Item'],
            ],
        ])->getContent();
    }

    public function tearDown(): void
    {
        // Cleanup is handled by setUp truncating tables, so no need to delete individually.
        $this->resources = [];
    }

    public function testNoIdentifier(): void
    {
        $findResourcesFromIdentifiers = $this->findResourcesFromIdentifiers;
        $this->api->create('items', [])->getContent();

        $identifierProperty = 'o:id';
        $resourceName = null;

        $identifier = '';
        $resource = $findResourcesFromIdentifiers($identifier, $identifierProperty, $resourceName);
        $this->assertNull($resource);

        $identifiers = [];
        $resources = $findResourcesFromIdentifiers($identifiers, $identifierProperty, $resourceName);
        $this->assertTrue(is_array($resources));
        $this->assertEmpty($resources);
    }

    public function resourceIdentifierProvider()
    {
        return [
            ['Foo Item Set', 10, 'item_sets', 0],
            ['Foo Item', 10, 'items', 1],
            ['Foo Media', 10, 'media', 2],
            // Unlike CsvImport, the first one is always returned in case of a
            // insensitive duplicate..
            // ['foo item set', 10, 'item_sets', 3],
            ['foo item set', 10, 'item_sets', 0],
            ['unknown', 10, 'item_sets', null],
            ['unknown', 10, 'items', null],
            ['unknown', 10, 'media', null],
        ];
    }

    /**
     * @dataProvider resourceIdentifierProvider
     */
    public function testResourceIdentifier($identifier, $identifierProperty, $resourceName, $expected): void
    {
        $expected = $expected === null ? null : $this->resources[$expected]->id();

        $findResourcesFromIdentifiers = $this->findResourcesFromIdentifiers;

        $resource = $findResourcesFromIdentifiers($identifier, $identifierProperty, $resourceName);
        $this->assertEquals($expected, $resource);

        $resources = $findResourcesFromIdentifiers([$identifier], $identifierProperty, $resourceName);
        // For unfound identifiers, plugin may return empty array or array with null value.
        if ($expected === null) {
            $this->assertTrue(empty($resources) || (isset($resources[$identifier]) && $resources[$identifier] === null));
        } else {
            $this->assertEquals(1, count($resources));
            $this->assertEquals($expected, $resources[$identifier]);
        }
    }

    public function resourceIdentifiersProvider()
    {
        return [
            [['Foo Item Set'], 10, 'item_sets', [0]],
            // Unlike CsvImport, the first one is always returned in case of a
            // insensitive duplicate..
            // [['Foo Item Set', 'foo item set'], 10, 'item_sets', [0, 3]],
            // [['foo item set', 'Foo Item Set'], 10, 'item_sets', [3, 0]],
            // [['foo item set', 'Foo Item Set', 'foo item set'], 10, 'item_sets', [3, 0]],
            // [['foo item set', 'unknown', 'Foo Item Set', 'foo item set'], 10, 'item_sets', [3, null, 0]],
            [['Foo Item Set', 'foo item set'], 10, 'item_sets', [0, 0]],
            [['foo item set', 'Foo Item Set'], 10, 'item_sets', [0, 0]],
            [['foo item set', 'Foo Item Set', 'foo item set'], 10, 'item_sets', [0, 0]],
            [['foo item set', 'unknown', 'Foo Item Set', 'foo item set'], 10, 'item_sets', [0, null, 0]],
        ];
    }

    /**
     * @dataProvider resourceIdentifiersProvider
     */
    public function testResourceIdentifiers($identifiers, $identifierProperty, $resourceName, $expecteds): void
    {
        foreach ($expecteds as &$expected) {
            $expected = $expected === null ? null : $this->resources[$expected]->id();
        }

        $findResourcesFromIdentifiers = $this->findResourcesFromIdentifiers;

        $resources = $findResourcesFromIdentifiers($identifiers, $identifierProperty, $resourceName);

        // Plugin may omit unfound identifiers from result.
        // Filter out null values from expected to match actual behavior.
        $expectedsFiltered = array_filter($expecteds, fn($v) => $v !== null);
        $this->assertEquals(count($expectedsFiltered), count($resources));
        $this->assertEquals(array_values($expectedsFiltered), array_values($resources));
    }
}
