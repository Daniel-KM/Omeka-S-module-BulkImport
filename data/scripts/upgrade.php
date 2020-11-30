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
 * @var \Omeka\Api\Manager $api
 */
$services = $serviceLocator;
$connection = $services->get('Omeka\Connection');
$entityManager = $services->get('Omeka\EntityManager');
$plugins = $services->get('ControllerPluginManager');
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
    $connection->exec($sql);
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
    $connection->exec($sql);
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
    $connection->exec($sql);
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
    $connection->exec($sql);
}

if (version_compare($oldVersion, '3.0.17', '<')) {
    $identity = $services->get('ControllerPluginManager')->get('identity');
    $ownerId = $identity()->getId();
    $sql = <<<SQL
INSERT INTO `bulk_importer` (`owner_id`, `label`, `reader_class`, `reader_config`, `processor_class`, `processor_config`) VALUES
($ownerId, 'Omeka S', 'BulkImport\\\\Reader\\\\OmekaSReader', NULL, 'BulkImport\\\\Processor\\\\OmekaSProcessor', NULL);
SQL;
    $connection->exec($sql);
}

if (version_compare($oldVersion, '3.3.21.1', '<')) {
    $sql = <<<'SQL'
ALTER TABLE bulk_import DROP FOREIGN KEY FK_BD98E8747FCFE58E;
SQL;
    $connection->exec($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_import DROP FOREIGN KEY FK_BD98E874BE04EA9;
SQL;
    $connection->exec($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_import
CHANGE importer_id importer_id INT DEFAULT NULL,
CHANGE job_id job_id INT DEFAULT NULL,
CHANGE comment comment VARCHAR(190) DEFAULT NULL,
CHANGE reader_params reader_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
CHANGE processor_params processor_params LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
SQL;
    $connection->exec($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_import ADD CONSTRAINT FK_BD98E8747FCFE58E FOREIGN KEY (importer_id) REFERENCES bulk_importer (id) ON DELETE SET NULL;
SQL;
    $connection->exec($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_import ADD CONSTRAINT FK_BD98E874BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE SET NULL;
SQL;
    $connection->exec($sql);
    $sql = <<<'SQL'
ALTER TABLE bulk_importer
CHANGE owner_id owner_id INT DEFAULT NULL,
CHANGE `label` `label` VARCHAR(190) DEFAULT NULL,
CHANGE reader_class reader_class VARCHAR(190) DEFAULT NULL,
CHANGE reader_config reader_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
CHANGE processor_class processor_class VARCHAR(190) DEFAULT NULL,
CHANGE processor_config processor_config LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)';
SQL;
    $connection->exec($sql);
}

if (version_compare($oldVersion, '3.3.21.5', '<')) {
    // @link https://www.doctrine-project.org/projects/doctrine-dbal/en/2.6/reference/types.html#array-types
    $sql = <<<'SQL'
ALTER TABLE `bulk_import`
CHANGE `reader_params` `reader_params` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
CHANGE `processor_params` `processor_params` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->exec($sql);
    $sql = <<<'SQL'
ALTER TABLE `bulk_importer`
CHANGE `reader_config` `reader_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
CHANGE `processor_config` `processor_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)';
SQL;
    $connection->exec($sql);
}
