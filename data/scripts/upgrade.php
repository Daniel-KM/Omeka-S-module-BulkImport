<?php declare(strict_types=1);

namespace BulkImport;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\Settings $settings
 * @var \Omeka\Api\Manager $api
 */
$services = $serviceLocator;
$connection = $services->get('Omeka\Connection');
$entityManager = $services->get('Omeka\EntityManager');
$plugins = $services->get('ControllerPluginManager');
$config = $services->get('Config');
$api = $plugins->get('api');

if (version_compare($oldVersion, '3.0.1', '<')) {
    $this->checkDependency();

    /** @var \Omeka\Module\Manager $moduleManager */
    $moduleManager = $services->get('Omeka\ModuleManager');
    $module = $moduleManager->getModule('Log');
    $version = $module->getDb('version');
    if (version_compare($version, '3.2.2', '<')) {
        throw new \Omeka\Module\Exception\ModuleCannotInstallException(
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
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
    }

    $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    if (!$this->checkDestinationDir($basePath . '/xsl')) {
        $message = new \Omeka\Stdlib\Message(
            'The directory "%s" is not writeable.', // @translate
            $basePath . '/xsl'
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
    }

    if (!$this->checkDestinationDir($basePath . '/bulk_import')) {
        $message = new \Omeka\Stdlib\Message(
            'The directory "%s" is not writeable.', // @translate
            $basePath . '/bulk_import'
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
