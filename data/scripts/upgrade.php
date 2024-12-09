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

if (version_compare($newVersion, '3.4.47', '>')
    && (version_compare($oldVersion, '3.3.35', '<') || version_compare($oldVersion, '3.4.35', '<'))
) {
    $message = new Message(
        $translate('To upgrade from version %1$s to version %2$s, you must upgrade to version %3$s first.'), // @translate
        $oldVersion, $newVersion, '3.4.47'
    );
    throw new ModuleCannotInstallException((string) $message);
}

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.64')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.64'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
        throw new ModuleCannotInstallException((string) $message);
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
    foreach ([
        'curation:dateStart' => 'curation:start',
        'curation:dateEnd' => 'curation:end',
    ] as $propertyOld => $propertyNew) {
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

// TODO Remove bulkimport_allow_empty_files and bulkimport_local_path in some version to keep config for EasyAdmin.
