<?php declare(strict_types=1);

namespace BulkImport;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$config = $services->get('Config');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

// For very old versions (< 3.3.35 or < 3.4.35), use the consolidated upgrade script
// that handles all migrations up to 3.4.47+ in a single pass.
if (version_compare($newVersion, '3.4.47', '>')
    && (version_compare($oldVersion, '3.3.35', '<') || version_compare($oldVersion, '3.4.35', '<'))
) {
    $filepath = __DIR__ . '/upgrade.3.4.47.php';
    if (file_exists($filepath)) {
        require_once $filepath;
        // After running the consolidated script, skip individual migrations
        // that were already handled by the consolidated script.
        // Set oldVersion to 3.4.48 to skip already-processed migrations
        // (the consolidated script includes all migrations up to and including 3.4.48).
        $oldVersion = '3.4.48';
    } else {
        $message = new Message(
            $translate('To upgrade from version %1$s to version %2$s, you must upgrade to version %3$s first.'), // @translate
            $oldVersion, $newVersion, '3.4.47'
        );
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }
}

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.80')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.80'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (!$this->checkModuleActiveVersion('Log', '3.4.33')) {
    $message = new PsrMessage(
        'The module {module} should be upgraded to version {version} or later.', // @translate
        ['module' => 'Log', 'version' => '3.4.33']
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
}

if (!$this->checkModuleActiveVersion('Mapper', '3.4.2')) {
    $message = new PsrMessage(
        'The module {module} should be upgraded to version {version} or later.', // @translate
        ['module' => 'Mapper', 'version' => '3.4.2']
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
}

if (version_compare($oldVersion, '3.3.36', '<')) {
    $user = $services->get('Omeka\AuthenticationService')->getIdentity();

    // The resource "bulk_importers" is not available during upgrade.
    require_once dirname(__DIR__, 2) . '/src/Entity/Import.php';
    require_once dirname(__DIR__, 2) . '/src/Entity/Importer.php';

    $filenames = [
        'xml - mets.php',
        'xml - mods.php',
    ];
    foreach ($filenames as $filename) {
        $filepath = dirname(__DIR__) . '/importers/' . $filename;
        $data = include $filepath;
        $data['owner'] = $user;
        $entity = new \BulkImport\Entity\Importer();
        foreach ($data as $key => $value) {
            $method = 'set' . ucfirst($key);
            $entity->$method($value);
        }
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $message = new PsrMessage(
        'It is now possible to import xml mets and xml mods.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.38', '<')) {
    $user = $services->get('Omeka\AuthenticationService')->getIdentity();

    // The resource "bulk_importers" is not available during upgrade.
    require_once dirname(__DIR__, 2) . '/src/Entity/Import.php';
    require_once dirname(__DIR__, 2) . '/src/Entity/Importer.php';

    $filenames = [
        'xml - ead.php',
    ];
    foreach ($filenames as $filename) {
        $filepath = dirname(__DIR__) . '/importers/' . $filename;
        $data = include $filepath;
        $data['owner'] = $user;
        $entity = new \BulkImport\Entity\Importer();
        foreach ($data as $key => $value) {
            $method = 'set' . ucfirst($key);
            $entity->$method($value);
        }
        $entityManager->persist($entity);
    }
    $entityManager->flush();

    $message = new PsrMessage(
        'It is now possible to import xml ead.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'It is now possible to pass params to xsl conversion for xml sources.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new PsrMessage(
        'It is now possible to create table of contents for IIIF viewer from mets and ead sources.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.39', '<')) {
    if (PHP_VERSION_ID < 70400) {
        $message = new PsrMessage(
            'Since version {version}, this module requires php 7.4.', // @translate
            ['version' => '3.4.39']
        );
        $messenger->addError($message);
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
    }
}

if (version_compare($oldVersion, '3.4.45', '<')) {
    $message = new PsrMessage(
        'It is now possible to process a bulk import and to upload files at the same time. It allows to bypass complex server config, where it is not possible to drop files on the server or to access an external server. Furthermore, the files can be zipped and they will be automatically unzipped.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.46', '<')) {
    // Update vocabulary via sql.
    $terms = [
        'curation:dateStart' => 'curation:start',
        'curation:dateEnd' => 'curation:end',
    ];
    foreach ($terms as $propertyOld => $propertyNew) {
        $propertyOld = $api->searchOne('properties', ['term' => $propertyOld])->getContent();
        $propertyNew = $api->searchOne('properties', ['term' => $propertyNew])->getContent();
        if ($propertyOld && $propertyNew) {
            // Remove the new property, it will be created below.
            $connection->executeStatement('UPDATE `value` SET `property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                'property_id_1' => $propertyOld->id(),
                'property_id_2' => $propertyNew->id(),
            ]);
            $connection->executeStatement('UPDATE `resource_template_property` SET `property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                'property_id_1' => $propertyOld->id(),
                'property_id_2' => $propertyNew->id(),
            ]);
            try {
                $connection->executeStatement('UPDATE `resource_template_property_data` SET `resource_template_property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                    'property_id_1' => $propertyOld->id(),
                    'property_id_2' => $propertyNew->id(),
                ]);
            } catch (\Exception $e) {
            }
            $connection->executeStatement('DELETE FROM `property` WHERE id = :property_id;', [
                'property_id' => $propertyNew->id(),
            ]);
        }
    }

    $sql = <<<SQL
        UPDATE `vocabulary`
        SET
            `comment` = 'Generic and common properties that are useful in Omeka for the curation of resources. The use of more common or more precise ontologies is recommended when it is possible.'
        WHERE `prefix` = 'curation'
        ;
        UPDATE `property`
        JOIN `vocabulary` on `vocabulary`.`id` = `property`.`vocabulary_id`
        SET
            `property`.`local_name` = 'start',
            `property`.`label` = 'Start',
            `property`.`comment` = 'A start related to the resource, for example the start of an embargo.'
        WHERE
            `vocabulary`.`prefix` = 'curation'
            AND `property`.`local_name` = 'dateStart'
        ;
        UPDATE `property`
        JOIN `vocabulary` on `vocabulary`.`id` = `property`.`vocabulary_id`
        SET
            `property`.`local_name` = 'end',
            `property`.`label` = 'End',
            `property`.`comment` = 'A end related to the resource, for example the end of an embargo.'
        WHERE
            `vocabulary`.`prefix` = 'curation'
            AND `property`.`local_name` = 'dateEnd'
        ;
        SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.4.47', '<')) {
    // Update vocabulary via sql.
    $sql = <<<SQL
        UPDATE `vocabulary`
        SET
            `comment` = 'Generic and common properties that are useful in Omeka for the curation of resources. The use of more common or more precise ontologies is recommended when it is possible.'
        WHERE `prefix` = 'curation'
        ;
        SQL;
    $connection->executeStatement($sql);

    $basePath = $services->get('ViewHelperManager')->get('BasePath');
    $message = new PsrMessage(
        'It is now possible {link}to bulk upload files{link_end} in a directory of the server for future bulk uploads.', // @translate
        ['link' => '<a href="' . rtrim($basePath(), '/') . '/admin/bulk/upload/files">', 'link_end' => '</a>']
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.48', '<')) {
    // Update bulk importer.
    $sql = <<<'SQL'
        UPDATE `bulk_importer`
        SET
            `reader_config` = '[]'
        WHERE `reader_config` IS NULL OR `reader_config` = "" OR `reader_config` = "{}"
        ;
        UPDATE `bulk_importer`
        SET
            `processor_config` = '[]'
        WHERE `processor_config` IS NULL OR `processor_config` = "" OR `processor_config` = "{}"
        ;
        UPDATE `bulk_importer`
        SET
            `config` = '[]'
        WHERE `config` IS NULL OR `config` = "" OR `config` = "{}"
        ;
        UPDATE `bulk_importer`
        SET
            `config` = CONCAT(
                '{',
                    '"importer":', `config`,
                    ',"reader":', `reader_config`,
                    ',"mapper":[]',
                    ',"processor":', `processor_config`,
                "}"
            )
        ;
        UPDATE `bulk_importer`
        SET
            `label` = "-"
        WHERE `label` IS NULL
        ;
        UPDATE `bulk_importer`
        SET
            `reader_class` = ""
        WHERE `reader_class` IS NULL
        ;
        UPDATE `bulk_importer`
        SET
            `processor_class` = ""
        WHERE `processor_class` IS NULL
        ;
        ALTER TABLE `bulk_importer`
        DROP `reader_config`,
        DROP `processor_config`,
        ADD `mapper`  VARCHAR(190) DEFAULT NULL AFTER `reader`,
        CHANGE `label` `label` VARCHAR(190) NOT NULL,
        CHANGE `reader_class` `reader` VARCHAR(190) NOT NULL,
        CHANGE `processor_class` `processor` VARCHAR(190) NOT NULL,
        CHANGE `config` `config` LONGTEXT NOT NULL COMMENT '(DC2Type:json)' AFTER `processor`
        ;
        SQL;
    $connection->executeStatement($sql);

    // Update bulk import.
    $sql = <<<'SQL'
        ALTER TABLE `bulk_import`
        ADD `params` LONGTEXT NOT NULL COMMENT '(DC2Type:json)' AFTER `processor_params`
        ;
        UPDATE `bulk_import`
        SET
            `reader_params` = '[]'
        WHERE `reader_params` IS NULL OR `reader_params` = "" OR `reader_params` = "{}"
        ;
        UPDATE `bulk_import`
        SET
            `processor_params` = '[]'
        WHERE `processor_params` IS NULL OR `processor_params` = "" OR `processor_params` = "{}"
        ;
        UPDATE `bulk_import`
        SET
            `params` = '[]'
        WHERE `params` IS NULL OR `params` = "" OR `params` = "{}"
        ;
        UPDATE `bulk_import`
        SET
            `params` = CONCAT(
                '{',
                    '"reader":', `reader_params`,
                    ',"mapping":[]',
                    ',"processor":', `processor_params`,
                "}"
            )
        ;
        UPDATE `bulk_import`
        SET `params` = REPLACE(`params`, ',"mapper":[', ',"mapping":[');
        ALTER TABLE `bulk_import`
        DROP `reader_params`,
        DROP `processor_params`
        ;
        SQL;
    $connection->executeStatement($sql);

    // Set mapping "manual" for all spreadsheet reader.
    $sql = <<<'SQL'
        UPDATE `bulk_importer`
        SET
            `mapper` = "manual"
        WHERE `reader` LIKE "%CsvReader"
            OR `reader` LIKE "%TsvReader"
            OR `reader` LIKE "%OpenDocumentSpreadsheetReader"
            OR `reader` LIKE "%SpreadsheetReader"
        ;
        SQL;
    $connection->executeStatement($sql);

    // Move mapping config to mapper.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('bulk_importer.id', 'bulk_importer.config')
        ->from('bulk_importer', 'bulk_importer')
        ->where('bulk_importer.config LIKE "%mapping_config%"')
        ->orderBy('bulk_importer.id', 'asc');
    $importerConfigs = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($importerConfigs as $id => $importerConfig) {
        $importerConfig = json_decode($importerConfig, true);
        $mappingConfig = $importerConfig['reader']['mapping_config'] ?? null;
        unset($importerConfig['reader']['mapping_config']);
        $connection->executeStatement(
            'UPDATE `bulk_importer` SET `mapper` = :mapper, `config` = :config WHERE `id` = :id',
            [
                'mapper' => $mappingConfig ?: null,
                'config' => json_encode($importerConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => $id,
            ],
            [
                'mapper' => $mappingConfig ? \Doctrine\DBAL\ParameterType::STRING : \Doctrine\DBAL\ParameterType::NULL,
                'config' => \Doctrine\DBAL\ParameterType::STRING,
                'id' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        );
    }

    // Move processor mapping into mapping.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('bulk_import.id', 'bulk_import.params')
        ->from('bulk_import', 'bulk_import')
        ->orderBy('bulk_import.id', 'asc');
    $importParameters = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($importParameters as $id => $importParams) {
        $importParams = json_decode($importParams, true);
        $mappingMapper = $importParams['mapping'] ?? [];
        $importParams['mapping'] = $mappingMapper ?: ($importParams['processor']['mapping'] ?? []);
        unset($importParams['processor']['mapping']);
        // The mapping config is now set in importer and cannot be changed in
        // import for now. Kept for future improvements.
        // unset($importParams['reader']['mapping_config']);
        $connection->executeStatement('UPDATE `bulk_import` SET `params` = :params WHERE `id` = :id', [
            'params' => json_encode($importParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'id' => $id,
        ]);
    }

    $message = new PsrMessage(
        'Import process has been clarified with three steps: reader, mapping and import.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.53', '<')) {
    $settings->delete('bulkimport_convert_html');
    $message = new PsrMessage(
        'The feature to convert documents to html has been removed from this module and will be reintegrated in another one.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.54', '<')) {
    // Set mapping "manual" for all spreadsheet reader.
    $sql = <<<'SQL'
        UPDATE `bulk_importer`
        SET
            `mapper` = "manual"
        WHERE `reader` LIKE "%CsvReader"
            OR `reader` LIKE "%TsvReader"
            OR `reader` LIKE "%OpenDocumentSpreadsheetReader"
            OR `reader` LIKE "%SpreadsheetReader"
        ;
        SQL;
    $connection->executeStatement($sql);

    $message = new PsrMessage(
        'The installer and the spreadsheet readers were fixed to allow to adapt mapping manually.' //@translate
    );
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'The feature to upload files without server limitation was moved to module {link}Easy Admin{link_end}. Install it if you need it.', //@translate
        [
            'link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin" target="_blank" rel="noopener">',
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.55', '<')) {
    $message = new PsrMessage(
        'The feature to extract metadata from media that was moved in another module in version 3.4.53 is reintegrated. You should set the option in main settings to use it.' // @translate
    );
    $messenger->addSuccess($message);

    /** @see \Common\ManageModuleAndResources::checkStringsInFiles() */
    $manageModuleAndResources = $this->getManageModuleAndResources();

    $checks = [
        '[mapping]',
    ];
    $result = $manageModuleAndResources->checkStringsInFiles($checks, 'data/mapping/json/file.*') ?? [];
    if ($result) {
        $message = new PsrMessage(
            'To keep the feature to extract metadata from media working, you should replace "[mapping]" by "[maps]" in the customized files "data/mapping/file.xxx". Matching files: {json}', // @translate
            ['json' => json_encode($result, 448)]
        );
        $messenger->addError($message);
        $logger = $services->get('Omeka\Logger');
        $logger->warn($message->getMessage(), $message->getContext());
    }
}

if (version_compare($oldVersion, '3.4.56', '<')) {
    // Update existing importers for default action for unidentified resources.
    $sql = <<<'SQL'
        UPDATE `bulk_importer`
        SET `config` = REPLACE(`config`, '"action_unidentified":"skip"', '"action_unidentified":"error"')
        SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.4.57', '<')) {
    // Included application/json and application/ld+json in the whitelist of
    // media-types and json and jsonld in the whitelist of extensions.
    $whitelist = $settings->get('media_type_whitelist', []);
    $whitelist = array_unique(array_merge(array_values($whitelist), [
        'application/json',
        'application/ld+json',
    ]));
    sort($whitelist);
    $settings->set('media_type_whitelist', $whitelist);

    $whitelist = $settings->get('extension_whitelist', []);
    $whitelist = array_unique(array_merge(array_values($whitelist), [
        'json',
        'jsonld',
    ]));
    sort($whitelist);
    $settings->set('extension_whitelist', $whitelist);

    $message = new PsrMessage(
        'It is now possible to import iiif presentation, not only iiif image.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A new option allows to clean inputs data, for example trim, change case, replace single quote by apostrophe, etc.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.59', '<')) {
    $message = new PsrMessage(
        'A spinner allows to check quickly if a job is really running (system state of the process).' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A new option allows to skip full check of files, so only their presence, not if they are well formed and thumbnailable.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.61', '<')) {
    // The internal mapping functionality has been moved to the Mapper module.
    // The Mapper module already migrates data from bulk_mapping table during
    // its installation, so we just need to drop the old table.
    // Check if bulk_mapping table still exists.
    try {
        $tableExists = $connection->executeQuery(
            'SHOW TABLES LIKE "bulk_mapping"'
        )->fetchOne();
        if ($tableExists) {
            $connection->executeStatement('DROP TABLE IF EXISTS `bulk_mapping`');
            $message = new PsrMessage(
                'The internal mapping table has been removed. Mappings are now managed by the Mapper module.' // @translate
            );
            $messenger->addSuccess($message);
        }
    } catch (\Exception $e) {
        // Ignore errors if table doesn't exist.
    }

    $message = new PsrMessage(
        'The mapping functionality is now provided by the Mapper module. Access mappings via the Mapper menu.' // @translate
    );
    $messenger->addWarning($message);

    // Update mapping_config paths after Mapper module folder reorganization.
    // Old paths like "module:json/iiif2xx.base.jsdot" become "module:iiif/iiif2xx.base.jsdot".
    // Covers all formats: JSON, XML, INI. Mirrors the path mappings from Mapper upgrade.php.
    $pathMappings = [
        // Content-DM (old flat → old underscore → new dot format)
        'base/content-dm.jsdot' => 'content-dm/content-dm.base.jsdot',
        'base/content-dm.jmespath' => 'content-dm/content-dm.base.jmespath',
        'base/content-dm.jsonpath' => 'content-dm/content-dm.base.jsonpath',
        'content-dm.jsdot' => 'content-dm/content-dm.base.jsdot',
        'content-dm.jmespath' => 'content-dm/content-dm.base.jmespath',
        'content-dm.jsonpath' => 'content-dm/content-dm.base.jsonpath',
        'json/content-dm.base.jsdot' => 'content-dm/content-dm.base.jsdot',
        'json/content-dm.base.jmespath' => 'content-dm/content-dm.base.jmespath',
        'json/content-dm.base.jsonpath' => 'content-dm/content-dm.base.jsonpath',
        'content-dm/content-dm_base.jsdot' => 'content-dm/content-dm.base.jsdot',
        'content-dm/content-dm_base.jmespath' => 'content-dm/content-dm.base.jmespath',
        'content-dm/content-dm_base.jsonpath' => 'content-dm/content-dm.base.jsonpath',
        // Content-DM unistra (old dot separator → new underscore)
        'json/content-dm.unistra.collection-3.jsdot' => 'content-dm/content-dm.unistra_collection-3.jsdot',
        'json/content-dm.unistra.collection-3.jmespath' => 'content-dm/content-dm.unistra_collection-3.jmespath',
        'json/content-dm.unistra.collection-3.jsonpath' => 'content-dm/content-dm.unistra_collection-3.jsonpath',
        // IIIF
        'base/iiif2xx.jsdot' => 'iiif/iiif2xx.base.jsdot',
        'base/iiif2xx.jmespath' => 'iiif/iiif2xx.base.jmespath',
        'base/iiif2xx.jsonpath' => 'iiif/iiif2xx.base.jsonpath',
        'iiif2xx.jsdot' => 'iiif/iiif2xx.base.jsdot',
        'iiif2xx.jmespath' => 'iiif/iiif2xx.base.jmespath',
        'iiif2xx.jsonpath' => 'iiif/iiif2xx.base.jsonpath',
        'json/iiif2xx.base.jsdot' => 'iiif/iiif2xx.base.jsdot',
        'json/iiif2xx.base.jmespath' => 'iiif/iiif2xx.base.jmespath',
        'json/iiif2xx.base.jsonpath' => 'iiif/iiif2xx.base.jsonpath',
        'json/iiif2xx.bnf.jsdot' => 'iiif/iiif2xx.bnf.jsdot',
        'json/iiif2xx.bnf.jmespath' => 'iiif/iiif2xx.bnf.jmespath',
        'json/iiif2xx.bnf.jsonpath' => 'iiif/iiif2xx.bnf.jsonpath',
        'json/iiif2xx.unistra.jsdot' => 'iiif/iiif2xx.unistra.jsdot',
        'json/iiif2xx.unistra.jmespath' => 'iiif/iiif2xx.unistra.jmespath',
        'json/iiif2xx.unistra.jsonpath' => 'iiif/iiif2xx.unistra.jsonpath',
        'iiif/iiif2xx_base.jsdot' => 'iiif/iiif2xx.base.jsdot',
        'iiif/iiif2xx_base.jmespath' => 'iiif/iiif2xx.base.jmespath',
        'iiif/iiif2xx_base.jsonpath' => 'iiif/iiif2xx.base.jsonpath',
        'iiif/iiif2xx_bnf.jsdot' => 'iiif/iiif2xx.bnf.jsdot',
        'iiif/iiif2xx_bnf.jmespath' => 'iiif/iiif2xx.bnf.jmespath',
        'iiif/iiif2xx_bnf.jsonpath' => 'iiif/iiif2xx.bnf.jsonpath',
        'iiif/iiif2xx_unistra.jsdot' => 'iiif/iiif2xx.unistra.jsdot',
        'iiif/iiif2xx_unistra.jmespath' => 'iiif/iiif2xx.unistra.jmespath',
        'iiif/iiif2xx_unistra.jsonpath' => 'iiif/iiif2xx.unistra.jsonpath',
        // File metadata (note: jsondot was a typo, now jsdot)
        'base/file.jsdot' => 'file/file.base.jsdot',
        'base/file.jsondot' => 'file/file.base.jsdot',
        'base/file.jmespath' => 'file/file.base.jmespath',
        'base/file.jsonpath' => 'file/file.base.jsonpath',
        'file.jsdot' => 'file/file.base.jsdot',
        'file.jsondot' => 'file/file.base.jsdot',
        'file.jmespath' => 'file/file.base.jmespath',
        'file.jsonpath' => 'file/file.base.jsonpath',
        'json/file.application_pdf.jsdot' => 'file/file.application_pdf.jsdot',
        'json/file.audio_mpeg.jsdot' => 'file/file.audio_mpeg.jsdot',
        'json/file.audio_wav.jsdot' => 'file/file.audio_wav.jsdot',
        'json/file.image_jpeg.jsdot' => 'file/file.image_jpeg.jsdot',
        'json/file.image_png.jsdot' => 'file/file.image_png.jsdot',
        'json/file.image_tiff.jsdot' => 'file/file.image_tiff.jsdot',
        'json/file.video_mp4.jsdot' => 'file/file.video_mp4.jsdot',
        'file/file_base.jsdot' => 'file/file.base.jsdot',
        'file/file_base.jmespath' => 'file/file.base.jmespath',
        'file/file_base.jsonpath' => 'file/file.base.jsonpath',
        // XML mappings (EAD)
        'xml/ead_to_omeka.xml' => 'ead/ead.base.xml',
        'xml/ead_presentation_to_omeka.xml' => 'ead/ead.presentation.xml',
        'xml/ead_components_to_omeka.xml' => 'ead/ead.components.xml',
        'ead/ead_base.xml' => 'ead/ead.base.xml',
        'ead/ead_presentation.xml' => 'ead/ead.presentation.xml',
        'ead/ead_components.xml' => 'ead/ead.components.xml',
        'ead/ead_tags.xml' => 'ead/ead.tags.xml',
        // XML mappings (Unimarc)
        'xml/unimarc_to_omeka.xml' => 'unimarc/unimarc.base.xml',
        'unimarc/unimarc_base.xml' => 'unimarc/unimarc.base.xml',
        // XML mappings (LIDO)
        'xml/lido_mc_to_omeka.xml' => 'lido/lido.mc.xml',
        'lido/lido_mc.xml' => 'lido/lido.mc.xml',
        // XML mappings (IdRef → RDF) - used by CopIdRef module
        'xml/idref_personne.xml' => 'rdf/rdf.idref_personne.xml',
        'idref/idref_personne.xml' => 'rdf/rdf.idref_personne.xml',
        // JSON mappings (Unimarc IdRef) - used by CopIdRef module
        'json/unimarc_idref_personne.json' => 'unimarc/unimarc.idref_personne.json',
        'json/unimarc_idref_collectivites.json' => 'unimarc/unimarc.idref_collectivites.json',
        'json/unimarc_idref_autre.json' => 'unimarc/unimarc.idref_autre.json',
        'idref/unimarc_idref_personne.json' => 'unimarc/unimarc.idref_personne.json',
        'idref/unimarc_idref_collectivites.json' => 'unimarc/unimarc.idref_collectivites.json',
        'idref/unimarc_idref_autre.json' => 'unimarc/unimarc.idref_autre.json',
        // Tables
        'json/geonames_countries.json' => 'tables/geonames.countries.json',
        'tables/geonames_countries.json' => 'tables/geonames.countries.json',
        // XSL transformations (used by XML readers)
        'xsl/identity.xslt1.xsl' => 'common/identity.xslt1.xsl',
        'xsl/identity.xslt2.xsl' => 'common/identity.xslt2.xsl',
        'xsl/identity.xslt3.xsl' => 'common/identity.xslt3.xsl',
        'xsl/ead_to_resources.xsl' => 'ead/ead_to_resources.xsl',
        'xsl/lido_to_resources.xsl' => 'lido/lido_to_resources.xsl',
        'xsl/mets_to_omeka.xsl' => 'mets/mets_to_omeka.xsl',
        'xsl/mets_exlibris_to_omeka.xsl' => 'mets/mets_exlibris_to_omeka.xsl',
        'xsl/mets_wrapped_exlibris_to_mets.xsl' => 'mets/mets_wrapped_exlibris_to_mets.xsl',
        'xsl/mods_to_omeka.xsl' => 'mods/mods_to_omeka.xsl',
        'xsl/sru.dublin-core_to_omeka.xsl' => 'sru/sru.dublin-core_to_omeka.xsl',
        'xsl/sru.dublin-core_with_file_gallica_to_omeka.xsl' => 'sru/sru.dublin-core_with_file_gallica_to_omeka.xsl',
        'xsl/sru.unimarc_to_resources.xsl' => 'unimarc/sru.unimarc_to_resources.xsl',
        'xsl/sru.unimarc_to_unimarc.xsl' => 'unimarc/sru.unimarc_to_unimarc.xsl',
    ];

    // Helper to update path with mappings.
    $updatePath = function ($path) use ($pathMappings) {
        $newPath = $path;
        foreach ($pathMappings as $old => $new) {
            // Handle "module:" prefix - the path is after the prefix.
            if (strpos($newPath, 'module:') !== false) {
                $newPath = str_replace('module:' . $old, 'module:' . $new, $newPath);
            } else {
                $newPath = str_replace($old, $new, $newPath);
            }
        }
        return $newPath;
    };

    // Update bulk_importer.mapper column (direct mapping path).
    $sql = 'SELECT id, mapper FROM bulk_importer WHERE mapper IS NOT NULL AND mapper != "" AND mapper != "manual" AND mapper NOT LIKE "mapping:%"';
    $results = $connection->executeQuery($sql)->fetchAllAssociative();
    $updatedMapper = 0;
    foreach ($results as $row) {
        $newPath = $updatePath($row['mapper']);
        if ($newPath !== $row['mapper']) {
            $connection->executeStatement(
                'UPDATE bulk_importer SET mapper = ? WHERE id = ?',
                [$newPath, $row['id']]
            );
            $updatedMapper++;
        }
    }

    // Update bulk_importer.config (mapping_config, xsl_sheet, xsl_sheet_pre in reader section).
    $sql = 'SELECT id, config FROM bulk_importer';
    $results = $connection->executeQuery($sql)->fetchAllAssociative();
    $updated = 0;
    foreach ($results as $row) {
        $config = json_decode($row['config'], true);
        $modified = false;
        // Update mapping_config path.
        $mappingConfig = $config['reader']['mapping_config'] ?? null;
        if ($mappingConfig) {
            $newPath = $updatePath($mappingConfig);
            if ($newPath !== $mappingConfig) {
                $config['reader']['mapping_config'] = $newPath;
                $modified = true;
            }
        }
        // Update xsl_sheet path.
        $xslSheet = $config['reader']['xsl_sheet'] ?? null;
        if ($xslSheet) {
            $newPath = $updatePath($xslSheet);
            if ($newPath !== $xslSheet) {
                $config['reader']['xsl_sheet'] = $newPath;
                $modified = true;
            }
        }
        // Update xsl_sheet_pre path.
        $xslSheetPre = $config['reader']['xsl_sheet_pre'] ?? null;
        if ($xslSheetPre) {
            $newPath = $updatePath($xslSheetPre);
            if ($newPath !== $xslSheetPre) {
                $config['reader']['xsl_sheet_pre'] = $newPath;
                $modified = true;
            }
        }
        if ($modified) {
            $connection->executeStatement(
                'UPDATE bulk_importer SET config = ? WHERE id = ?',
                [json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $row['id']]
            );
            $updated++;
        }
    }

    // Update bulk_import.params (mapping_config, xsl_sheet, xsl_sheet_pre in reader section).
    $sql = 'SELECT id, params FROM bulk_import';
    $results = $connection->executeQuery($sql)->fetchAllAssociative();
    $updatedImports = 0;
    foreach ($results as $row) {
        $params = json_decode($row['params'], true);
        $modified = false;
        // Update mapping_config path.
        $mappingConfig = $params['reader']['mapping_config'] ?? null;
        if ($mappingConfig) {
            $newPath = $updatePath($mappingConfig);
            if ($newPath !== $mappingConfig) {
                $params['reader']['mapping_config'] = $newPath;
                $modified = true;
            }
        }
        // Update xsl_sheet path.
        $xslSheet = $params['reader']['xsl_sheet'] ?? null;
        if ($xslSheet) {
            $newPath = $updatePath($xslSheet);
            if ($newPath !== $xslSheet) {
                $params['reader']['xsl_sheet'] = $newPath;
                $modified = true;
            }
        }
        // Update xsl_sheet_pre path.
        $xslSheetPre = $params['reader']['xsl_sheet_pre'] ?? null;
        if ($xslSheetPre) {
            $newPath = $updatePath($xslSheetPre);
            if ($newPath !== $xslSheetPre) {
                $params['reader']['xsl_sheet_pre'] = $newPath;
                $modified = true;
            }
        }
        if ($modified) {
            $connection->executeStatement(
                'UPDATE bulk_import SET params = ? WHERE id = ?',
                [json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $row['id']]
            );
            $updatedImports++;
        }
    }

    $totalUpdated = $updatedMapper + $updated + $updatedImports;
    if ($totalUpdated) {
        $message = new PsrMessage(
            'Updated {total} record(s) with new Mapper module paths: {mapper} importer mapper(s), {config} importer config(s), {imports} import(s).', // @translate
            ['total' => $totalUpdated, 'mapper' => $updatedMapper, 'config' => $updated, 'imports' => $updatedImports]
        );
        $messenger->addSuccess($message);
    }
}
