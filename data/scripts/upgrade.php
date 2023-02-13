<?php declare(strict_types=1);

namespace BulkImport;

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
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (version_compare($oldVersion, '3.0.1', '<')) {
    $this->checkDependency();

    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('Log');
    $version = $module->getDb('version');
    if (version_compare($version, '3.2.2', '<')) {
        throw new ModuleCannotInstallException(
            'BulkImport requires module Log version 3.2.2 or higher.' // @translate
        );
    }

    $sql = <<<'SQL'
ALTER TABLE bulk_log DROP FOREIGN KEY FK_3B78A07DB6A263D9;
DROP TABLE bulk_log;
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.0.2', '<')) {
    $sql = <<<'SQL'
ALTER TABLE bulk_import
    ADD job_id INT DEFAULT NULL AFTER importer_id,
    DROP status,
    DROP started,
    DROP ended,
    CHANGE importer_id importer_id INT DEFAULT NULL,
    CHANGE reader_params reader_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    CHANGE processor_params processor_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
ALTER TABLE bulk_import
    ADD CONSTRAINT FK_BD98E874BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id);
CREATE UNIQUE INDEX UNIQ_BD98E874BE04EA9 ON bulk_import (job_id);
ALTER TABLE bulk_importer
    CHANGE name name VARCHAR(190) DEFAULT NULL,
    CHANGE reader_name reader_name VARCHAR(190) DEFAULT NULL,
    CHANGE reader_config reader_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    CHANGE processor_name processor_name VARCHAR(190) DEFAULT NULL,
    CHANGE processor_config processor_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.0.3', '<')) {
    $sql = <<<'SQL'
ALTER TABLE bulk_import
    CHANGE importer_id importer_id INT DEFAULT NULL,
    CHANGE job_id job_id INT DEFAULT NULL,
    CHANGE reader_params reader_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    CHANGE processor_params processor_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
ALTER TABLE bulk_importer
    ADD owner_id INT DEFAULT NULL AFTER id,
    CHANGE name `label` VARCHAR(190) DEFAULT NULL,
    CHANGE reader_name reader_class VARCHAR(190) DEFAULT NULL,
    CHANGE processor_name processor_class VARCHAR(190) DEFAULT NULL,
    CHANGE reader_config reader_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    CHANGE processor_config processor_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
ALTER TABLE bulk_importer
    ADD CONSTRAINT FK_2DAF62D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE SET NULL;
CREATE INDEX IDX_2DAF62D7E3C61F9 ON bulk_importer (owner_id);
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.0.11', '<')) {
    $user = $services->get('Omeka\AuthenticationService')->getIdentity();

    // The resource "bulk_exporters" is not available during upgrade.
    require_once dirname(__DIR__, 2) . '/src/Entity/Import.php';
    require_once dirname(__DIR__, 2) . '/src/Entity/Importer.php';

    $directory = new \RecursiveDirectoryIterator(dirname(__DIR__) . '/importers', \RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new \RecursiveIteratorIterator($directory);
    foreach ($iterator as $filepath => $file) {
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
}

if (version_compare($oldVersion, '3.0.16', '<')) {
    $sql = <<<'SQL'
ALTER TABLE `bulk_import`
    ADD `comment` VARCHAR(190) DEFAULT NULL AFTER `job_id`;
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.0.17', '<')) {
    $identity = $services->get('ControllerPluginManager')->get('identity');
    $ownerId = $identity()->getId();
    $sql = <<<SQL
INSERT INTO `bulk_importer` (`owner_id`, `label`, `reader_class`, `reader_config`, `processor_class`, `processor_config`) VALUES
($ownerId, 'Omeka S', 'BulkImport\\\\Reader\\\\OmekaSReader', NULL, 'BulkImport\\\\Processor\\\\OmekaSProcessor', NULL);
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.3.21.1', '<')) {
    $sql = <<<'SQL'
ALTER TABLE bulk_import DROP FOREIGN KEY FK_BD98E8747FCFE58E;
SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_import DROP FOREIGN KEY FK_BD98E874BE04EA9;
SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_import
CHANGE importer_id importer_id INT DEFAULT NULL,
CHANGE job_id job_id INT DEFAULT NULL,
CHANGE comment comment VARCHAR(190) DEFAULT NULL,
CHANGE reader_params reader_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
CHANGE processor_params processor_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_import ADD CONSTRAINT FK_BD98E8747FCFE58E FOREIGN KEY (importer_id) REFERENCES bulk_importer (id) ON DELETE SET NULL;
SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_import ADD CONSTRAINT FK_BD98E874BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE SET NULL;
SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_importer
CHANGE owner_id owner_id INT DEFAULT NULL,
CHANGE `label` `label` VARCHAR(190) DEFAULT NULL,
CHANGE reader_class reader_class VARCHAR(190) DEFAULT NULL,
CHANGE reader_config reader_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
CHANGE processor_class processor_class VARCHAR(190) DEFAULT NULL,
CHANGE processor_config processor_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.3.21.5', '<')) {
    // @link https://www.doctrine-project.org/projects/doctrine-dbal/en/2.6/reference/types.html#array-types
    $sql = <<<'SQL'
ALTER TABLE `bulk_import`
CHANGE `reader_params` `reader_params` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
CHANGE `processor_params` `processor_params` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
ALTER TABLE `bulk_importer`
CHANGE `reader_config` `reader_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
CHANGE `processor_config` `processor_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->executeStatement($sql);
}

$migrate_3_3_22_0 = function () use ($services, $connection, $config): void {
    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('Log');
    $version = $module->getDb('version');
    if (version_compare($version, '3.3.12.7', '<')) {
        $message = new \Omeka\Stdlib\Message(
            'This module requires the module "%s", version %s or above.', // @translate
            'Log', '3.3.12.7'
        );
        throw new ModuleCannotInstallException((string) $message);
    }

    $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    if (!$this->checkDestinationDir($basePath . '/xsl')) {
        $message = new \Omeka\Stdlib\Message(
            'The directory "%s" is not writeable.', // @translate
            $basePath . '/xsl'
        );
        throw new ModuleCannotInstallException((string) $message);
    }

    if (!$this->checkDestinationDir($basePath . '/bulk_import')) {
        $message = new \Omeka\Stdlib\Message(
            'The directory "%s" is not writeable.', // @translate
            $basePath . '/bulk_import'
        );
        throw new ModuleCannotInstallException((string) $message);
    }

    $sql = <<<'SQL'
UPDATE `bulk_importer`
SET
    `bulk_importer`.`processor_config` = REPLACE(
        REPLACE(
            `bulk_importer`.`processor_config`,
            '"identifier_name":["o:id","dcterms:identifier"]',
            '"identifier_name":["dcterms:identifier"]'
        ),
        '"identifier_name":["dcterms:identifier","o:id"]',
        '"identifier_name":["dcterms:identifier"]'
    )
WHERE
    `bulk_importer`.`processor_config` LIKE '%"identifier\_name":["o:id","dcterms:identifier"]%'
    OR `bulk_importer`.`processor_config` LIKE '%"identifier\_name":["dcterms:identifier","o:id"]%';
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        // The upgrade failed in previous step, but ok this time.
    }

    $identity = $services->get('ControllerPluginManager')->get('identity');
    $ownerId = $identity()->getId();
    $sql = <<<SQL
INSERT INTO `bulk_importer` (`owner_id`, `label`, `reader_class`, `reader_config`, `processor_class`, `processor_config`) VALUES
($ownerId, 'Xml Items', 'BulkImport\\\\Reader\\\\XmlReader', '{"xsl_sheet":"modules/BulkImport/data/xsl/identity.xslt1.xsl"}', 'BulkImport\\\\Processor\\\\OmekaSProcessor', '{"o:resource_template":"","o:resource_class":"","o:owner":"current","o:is_public":null,"action":"create","action_unidentified":"skip","identifier_name":["o:id","dcterms:identifier"],"allow_duplicate_identifiers":false,"entries_to_skip":0,"entries_by_batch":"","resource_type":""}');
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        // The upgrade failed in previous step, but ok this time.
    }

    $sql = <<<'SQL'
ALTER TABLE bulk_import
CHANGE importer_id importer_id INT DEFAULT NULL,
CHANGE job_id job_id INT DEFAULT NULL,
CHANGE comment comment VARCHAR(190) DEFAULT NULL,
CHANGE reader_params reader_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
CHANGE processor_params processor_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
ADD undo_job_id INT DEFAULT NULL AFTER job_id,
ADD CONSTRAINT FK_BD98E8744C276F75 FOREIGN KEY (undo_job_id) REFERENCES job (id) ON DELETE SET NULL;
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        // The upgrade failed in previous step, but ok this time.
    }

    $sql = <<<'SQL'
ALTER TABLE bulk_importer
CHANGE owner_id owner_id INT DEFAULT NULL,
CHANGE `label` `label` VARCHAR(190) DEFAULT NULL,
CHANGE reader_class reader_class VARCHAR(190) DEFAULT NULL,
CHANGE reader_config reader_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
CHANGE processor_class processor_class VARCHAR(190) DEFAULT NULL,
CHANGE processor_config processor_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)';
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        // The upgrade failed in previous step, but ok this time.
    }

    $sql = <<<'SQL'
CREATE TABLE `bulk_imported` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `job_id` int(11) NOT NULL,
    `entity_id` int(11) NOT NULL,
    `resource_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (`id`),
    KEY `IDX_F60E437CB6A263D9` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE bulk_imported ADD CONSTRAINT FK_F60E437CB6A263D9 FOREIGN KEY (job_id) REFERENCES job (id);
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        // The upgrade failed in previous step, but ok this time.
    }
};

$v3322 = false;
if (version_compare($oldVersion, '3.3.22.0', '<')) {
    $v3322 = true;
    $migrate_3_3_22_0();
}

if (version_compare($oldVersion, '3.3.24.0', '<') && !$v3322) {
    // In some cases, the update wasn't processed.
    try {
        $connection->executeQuery('SELECT `undo_job_id` FROM `bulk_import` LIMIT 1;');
    } catch (\Exception $e) {
        $migrate_3_3_22_0();
        // Fix a strange issue.
        $sql = <<<'SQL'
UPDATE `module`
SET
    `module`.`version` = "3.3.24.0"
WHERE
    `module`.`id` = "BulkImport";
SQL;
        $connection->executeStatement($sql);
    }
}

if (version_compare($oldVersion, '3.3.25.0', '<') && !$v3322) {
    // Fix a strange issue.
    $sql = <<<'SQL'
UPDATE `module`
SET
    `module`.`version` = "3.3.25.0"
WHERE
    `module`.`id` = "BulkImport";
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.3.28.0', '<')) {
    $sql = <<<'SQL'
UPDATE `bulk_importer`
SET
    `processor_config` = REPLACE(`processor_config`, '"resource_type":', '"resource_name":')
WHERE
    `processor_config` IS NOT NULL
    AND `processor_config` LIKE '%"resource#_type":%' ESCAPE '#'
;
SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
UPDATE `bulk_import`
SET
    `processor_params` = REPLACE(`processor_params`, '"resource_type":', '"resource_name":')
WHERE
    `processor_params` IS NOT NULL
    AND `processor_params` LIKE '%"resource#_type":%' ESCAPE '#'
;
SQL;
    $connection->executeStatement($sql);

    // Do a whole update to clean existing tables.
    $sqls = <<<'SQL'
ALTER TABLE `bulk_import`
    DROP INDEX FK_BD98E8744C276F75,
    ADD UNIQUE INDEX UNIQ_BD98E8744C276F75 (`undo_job_id`);
ALTER TABLE `bulk_import`
    CHANGE `importer_id` `importer_id` INT DEFAULT NULL,
    CHANGE `job_id` `job_id` INT DEFAULT NULL,
    CHANGE `undo_job_id` `undo_job_id` INT DEFAULT NULL,
    CHANGE `comment` `comment` VARCHAR(190) DEFAULT NULL,
    CHANGE `reader_params` `reader_params` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
    CHANGE `processor_params` `processor_params` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)';
ALTER TABLE `bulk_importer`
    CHANGE `owner_id` `owner_id` INT DEFAULT NULL,
    CHANGE `label` `label` VARCHAR(190) DEFAULT NULL,
    CHANGE `reader_class` `reader_class` VARCHAR(190) DEFAULT NULL,
    CHANGE `reader_config` `reader_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
    CHANGE `processor_class` `processor_class` VARCHAR(190) DEFAULT NULL,
    CHANGE `processor_config` `processor_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)';
ALTER TABLE `bulk_imported`
    DROP FOREIGN KEY FK_F60E437CB6A263D9;
ALTER TABLE `bulk_imported`
    CHANGE `resource_type` `entity_name` VARCHAR(190) NOT NULL AFTER `entity_id`;
DROP INDEX idx_f60e437cb6a263d9 ON `bulk_imported`;
CREATE INDEX IDX_F60E437CBE04EA9 ON `bulk_imported` (`job_id`);
ALTER TABLE `bulk_imported`
    ADD CONSTRAINT FK_F60E437CB6A263D9 FOREIGN KEY (`job_id`) REFERENCES `job` (`id`);
SQL;
    foreach (array_filter(array_map('trim', explode(";\n", $sqls))) as $sql) {
        $connection->executeStatement($sql);
    }
}

if (version_compare($oldVersion, '3.3.30.0', '<')) {
    $message = new Message(
        'It’s now possible to upload files and directories in bulk in item form.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.31.0', '<')) {
    require_once __DIR__ . '/upgrade_vocabulary.php';

    $sql = <<<'SQL'
CREATE TABLE `bulk_mapping` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `owner_id` INT DEFAULT NULL,
    `label` VARCHAR(190) NOT NULL,
    `mapping` LONGTEXT NOT NULL,
    `created` DATETIME NOT NULL,
    `modified` DATETIME DEFAULT NULL,
    UNIQUE INDEX UNIQ_7DA82350EA750E8 (`label`),
    INDEX IDX_7DA823507E3C61F9 (`owner_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE `bulk_mapping` ADD CONSTRAINT FK_7DA823507E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        // In some cases, the table already exists, so it may be skipped.
    }

    $sql = <<<'SQL'
UPDATE `bulk_importer`
SET
    `reader_config` = REPLACE(
        REPLACE(
            `reader_config`,
            '\\/data\\/xsl\\/',
            '\\/data\\/mapping\\/xsl\\/'
        ),
        '\\/data\\/ini\\/',
        '\\/data\\/mapping\\/json\\/'
    )
WHERE
    `reader_config` IS NOT NULL
    AND `reader_config` LIKE '%/data%'
;
SQL;
    $connection->executeStatement($sql);
    $sql = <<<'SQL'
UPDATE `bulk_importer`
SET
    `processor_config` = REPLACE(
        REPLACE(
            `processor_config`,
            '\\/data\\/xsl\\/',
            '\\/data\\/mapping\\/xsl\\/'
        ),
        '\\/data\\/ini\\/',
        '\\/data\\/mapping\\/json\\/'
    )
WHERE
    `reader_config` IS NOT NULL
    AND `processor_config` LIKE '%/data%'
;
SQL;
    $connection->executeStatement($sql);

    // Module resources are not available during upgrade.
    // Update importer files.
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('bulk_importer.id', 'bulk_importer.reader_config')
        ->from('bulk_importer', 'bulk_importer')
        ->where('bulk_importer.reader_config IS NOT NULL')
        ->andWhere('bulk_importer.reader_config != "[]"')
        ->andWhere('bulk_importer.reader_config != "{}"')
        ->orderBy('bulk_importer.id', 'asc');
    $importerReaderConfigs = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($importerReaderConfigs as $id => $importerReaderConfig) {
        $readerConfig = json_decode($importerReaderConfig, true) ?: [];
        if (isset($readerConfig['xsl_sheet'])) {
            if (mb_substr($readerConfig['xsl_sheet'], 0, 5) === 'user:') {
                $readerConfig['xsl_sheet'] = 'user:xsl/' . trim(mb_substr($readerConfig['xsl_sheet'], 5));
            } else {
                $readerConfig['xsl_sheet'] = 'module:xsl/' . trim($readerConfig['xsl_sheet']);
            }
        }
        if (array_key_exists('mapping_file', $readerConfig)) {
            $mappingFile = $readerConfig['mapping_file'];
            unset($readerConfig['mapping_file']);
            if ($mappingFile) {
                $extension = pathinfo($mappingFile, PATHINFO_EXTENSION);
                $subDir = $extension === 'xml' ? 'xml/' : 'json/';
                if (mb_substr($mappingFile, 0, 5) === 'user:') {
                    $readerConfig['mapping_config'] = 'user:' . $subDir . trim(mb_substr($mappingFile, 5));
                } else {
                    $readerConfig['mapping_config'] = 'module:' . $subDir . $mappingFile;
                }
            }
        }
        $sql = <<<'SQL'
UPDATE `bulk_importer`
SET
    `reader_config` = ?
WHERE
    `id` = ?
;
SQL;
        $connection->executeStatement($sql, [
            json_encode($readerConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $id,
        ]);
    }

    $message = new Message(
        'It’s now possible to edit online the mappings between any json or xml source and omeka resources.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'It’s now possible to create import/update tasks to be run via command line or cron (for module Easy Admin).' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.33.0', '<')) {
    require_once __DIR__ . '/upgrade_vocabulary.php';
}

if (version_compare($oldVersion, '3.3.33.1', '<')) {
    $sql = <<<'SQL'
ALTER TABLE bulk_importer
ADD `config` LONGTEXT NOT NULL COMMENT '(DC2Type:json)' AFTER `label`,
CHANGE `owner_id` `owner_id` INT DEFAULT NULL,
CHANGE `label` `label` VARCHAR(190) DEFAULT NULL,
CHANGE `reader_class` `reader_class` VARCHAR(190) DEFAULT NULL,
CHANGE `reader_config` `reader_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
CHANGE `processor_class` `processor_class` VARCHAR(190) DEFAULT NULL,
CHANGE `processor_config` `processor_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)'
;
SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Doctrine\DBAL\Exception\TableNotFoundException $e) {
        // May be an issue with an old install. So reinstall it.
        $filepath = dirname(__DIR__) . '/install/schema.sql';
        if (!file_exists($filepath) || !filesize($filepath) || !is_readable($filepath)) {
            throw new ModuleCannotInstallException('Install sql file does not exist'); // @translate
        }
        $sql = file_get_contents($filepath);
        $sqls = array_filter(array_map('trim', explode(";\n", $sql)));
        foreach ($sqls as $sql) {
            try {
                // Avoid issue on table exists.
                $connection->executeStatement($sql);
            } catch (\Exception $e) {
            }
        }
    }

    $sql = <<<'SQL'
UPDATE `bulk_importer`
SET `config` = '{}'
;
SQL;
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.3.34', '<')) {
    require_once __DIR__ . '/upgrade_vocabulary.php';

    $messenger->addSuccess($message);
    $message = new Message(
        'New mappers where added to import iiif manifests.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'Two new formats are supported to write mappings: jsonpath and  jmespath. See examples with iiif manifest mappings.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.3.35', '<')) {
    $user = $services->get('Omeka\AuthenticationService')->getIdentity();

    // The resource "bulk_importers" is not available during upgrade.
    require_once dirname(__DIR__, 2) . '/src/Entity/Import.php';
    require_once dirname(__DIR__, 2) . '/src/Entity/Importer.php';

    $filenames = [
        'csv - assets.php',
        'ods - assets.php',
        'tsv - assets.php',
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

    // Check configs for new formats.
    // Module resources are not available during upgrade.
    // Update mapping files.
    $replaceIni = [];
    $replaceXml = [];
    $dataTypes = $services->get('Omeka\DataTypeManager')->getRegisteredNames();
    $dataTypes[] = 'customvocab:';
    foreach ($dataTypes as $dataType) {
        $replaceIni[' ; ' . $dataType] = ' ^^' . $dataType;
        $replaceIni['; ' . $dataType] = ' ^^' . $dataType;
        $replaceIni[' ;' . $dataType] = ' ^^' . $dataType;
        $replaceIni[';' . $dataType] = ' ^^' . $dataType;
        $replaceXml[' ; ' . $dataType] = ' ' . $dataType;
        $replaceXml['; ' . $dataType] = ' ' . $dataType;
        $replaceXml[' ;' . $dataType] = ' ' . $dataType;
        $replaceXml[';' . $dataType] = ' ' . $dataType;
    }
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('bulk_mapping.id', 'bulk_mapping.mapping')
        ->from('bulk_mapping', 'bulk_mapping')
        ->orderBy('bulk_mapping.id', 'asc');
    $mappingConfigs = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($mappingConfigs as $id => $mappingConfig) {
        $mappingConfig = substr(trim($mappingConfigs), 0, 1) === '<'
            ? str_replace(array_keys($replaceXml), array_values($replaceXml), $mappingConfig)
            : str_replace(array_keys($replaceIni), array_values($replaceIni), $mappingConfig);
        $sql = <<<'SQL'
UPDATE `bulk_importer`
SET
    `reader_config` = ?
WHERE
    `id` = ?
;
SQL;
        $connection->executeStatement($sql, [
            $mappingConfig,
            $id,
        ]);
    }

    $message = new Message(
        'It’s now possible to import and update assets and to attach them to resources.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new Message(
        'The format of destination metadata has been improved: spaces after "^^", "@" and "§" are no more managed; for multiple datatypes, the ";" was replaced by "^^"; for custom vocab with a label, the label should be wrapped by quotes or double quotes. Check your custom configs if needed.' // @translate
    );
    $messenger->addWarning($message);
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

    $message = new Message(
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

    $message = new Message(
        'It is now possible to import xml ead.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'It is now possible to pass params to xsl conversion for xml sources.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'It is now possible to create table of contents for IIIF viewer from mets and ead sources.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.39', '<')) {
    if (PHP_VERSION_ID < 70400) {
        $message = new Message(
            'Since version %s, this module requires php 7.4.', // @translate
            '3.4.39'
        );
        throw new ModuleCannotInstallException((string) $message);
    }
}
