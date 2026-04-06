<?php declare(strict_types=1);

namespace BulkImport;

use Common\Stdlib\PsrMessage;

/**
 * Upgrade script for BulkImport module to version 3.4.47+.
 *
 * This script consolidates all migrations from very old versions (< 3.3.35 or < 3.4.35)
 * to version 3.4.47+, allowing direct upgrade without installing intermediate versions.
 *
 * Based on upgrade.php from tag 3.4.47 (commit 5ae265b09).
 *
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var array $config
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$config = $services->get('Config');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

$message = new PsrMessage(
    'Running consolidated upgrade from version {old_version} to {new_version}.', // @translate
    ['old_version' => $oldVersion, 'new_version' => $newVersion]
);
$messenger->addNotice($message);

/**
 * Helper function to check if a column exists in a table.
 */
$columnExists = function (string $table, string $column) use ($connection): bool {
    try {
        $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
        $result = $connection->executeQuery($sql)->fetchOne();
        return $result !== false;
    } catch (\Throwable $e) {
        return false;
    }
};

/**
 * Helper function to check if a table exists.
 */
$tableExists = function (string $table) use ($connection): bool {
    try {
        $connection->executeQuery("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
};

/**
 * Helper function to check if an index exists.
 */
$indexExists = function (string $table, string $indexName) use ($connection): bool {
    try {
        $sql = "SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'";
        $result = $connection->executeQuery($sql)->fetchOne();
        return $result !== false;
    } catch (\Throwable $e) {
        return false;
    }
};

/**
 * Helper function to check if a foreign key exists.
 */
$foreignKeyExists = function (string $table, string $fkName) use ($connection): bool {
    try {
        $dbName = $connection->getDatabase();
        $sql = "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = '$dbName' AND TABLE_NAME = '$table'
                AND CONSTRAINT_NAME = '$fkName' AND CONSTRAINT_TYPE = 'FOREIGN KEY'";
        $result = $connection->executeQuery($sql)->fetchOne();
        return $result !== false;
    } catch (\Throwable $e) {
        return false;
    }
};

// ============================================================================
// Migration for version < 3.0.1: Drop bulk_log table if exists
// ============================================================================

if ($tableExists('bulk_log')) {
    $message = new PsrMessage('Dropping obsolete bulk_log table.'); // @translate
    $messenger->addNotice($message);
    try {
        $connection->executeStatement('ALTER TABLE bulk_log DROP FOREIGN KEY FK_3B78A07DB6A263D9');
    } catch (\Throwable $e) {
        // FK may not exist
    }
    try {
        $connection->executeStatement('DROP TABLE bulk_log');
    } catch (\Throwable $e) {
        // Table may not exist
    }
}

// ============================================================================
// Migration for version < 3.0.2: Add job_id, remove status/started/ended
// ============================================================================

if ($columnExists('bulk_import', 'status')) {
    $message = new PsrMessage('Migrating bulk_import table (3.0.2 structure).'); // @translate
    $messenger->addNotice($message);

    try {
        if (!$columnExists('bulk_import', 'job_id')) {
            $connection->executeStatement('ALTER TABLE bulk_import ADD job_id INT DEFAULT NULL AFTER importer_id');
        }
        if ($columnExists('bulk_import', 'status')) {
            $connection->executeStatement('ALTER TABLE bulk_import DROP status');
        }
        if ($columnExists('bulk_import', 'started')) {
            $connection->executeStatement('ALTER TABLE bulk_import DROP started');
        }
        if ($columnExists('bulk_import', 'ended')) {
            $connection->executeStatement('ALTER TABLE bulk_import DROP ended');
        }
        if (!$foreignKeyExists('bulk_import', 'FK_BD98E874BE04EA9') && $columnExists('bulk_import', 'job_id')) {
            $connection->executeStatement('ALTER TABLE bulk_import ADD CONSTRAINT FK_BD98E874BE04EA9 FOREIGN KEY (job_id) REFERENCES job (id)');
        }
        if (!$indexExists('bulk_import', 'UNIQ_BD98E874BE04EA9') && $columnExists('bulk_import', 'job_id')) {
            $connection->executeStatement('CREATE UNIQUE INDEX UNIQ_BD98E874BE04EA9 ON bulk_import (job_id)');
        }
    } catch (\Throwable $e) {
        // Continue on error
    }
}

// ============================================================================
// Migration for version < 3.0.3: Add owner_id, rename columns
// ============================================================================

if ($columnExists('bulk_importer', 'name') || $columnExists('bulk_importer', 'reader_name')) {
    $message = new PsrMessage('Migrating bulk_importer table (3.0.3 structure).'); // @translate
    $messenger->addNotice($message);

    try {
        if (!$columnExists('bulk_importer', 'owner_id')) {
            $connection->executeStatement('ALTER TABLE bulk_importer ADD owner_id INT DEFAULT NULL AFTER id');
            if (!$foreignKeyExists('bulk_importer', 'FK_2DAF62D7E3C61F9')) {
                $connection->executeStatement('ALTER TABLE bulk_importer ADD CONSTRAINT FK_2DAF62D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE SET NULL');
            }
            if (!$indexExists('bulk_importer', 'IDX_2DAF62D7E3C61F9')) {
                $connection->executeStatement('CREATE INDEX IDX_2DAF62D7E3C61F9 ON bulk_importer (owner_id)');
            }
        }
        if ($columnExists('bulk_importer', 'name') && !$columnExists('bulk_importer', 'label')) {
            $connection->executeStatement('ALTER TABLE bulk_importer CHANGE `name` `label` VARCHAR(190) DEFAULT NULL');
        }
        if ($columnExists('bulk_importer', 'reader_name') && !$columnExists('bulk_importer', 'reader_class')) {
            $connection->executeStatement('ALTER TABLE bulk_importer CHANGE reader_name reader_class VARCHAR(190) DEFAULT NULL');
        }
        if ($columnExists('bulk_importer', 'processor_name') && !$columnExists('bulk_importer', 'processor_class')) {
            $connection->executeStatement('ALTER TABLE bulk_importer CHANGE processor_name processor_class VARCHAR(190) DEFAULT NULL');
        }
    } catch (\Throwable $e) {
        // Continue on error
    }
}

// ============================================================================
// Migration for version < 3.0.16: Add comment column
// ============================================================================

if (!$columnExists('bulk_import', 'comment')) {
    $message = new PsrMessage('Adding comment column to bulk_import.'); // @translate
    $messenger->addNotice($message);
    try {
        $connection->executeStatement('ALTER TABLE `bulk_import` ADD `comment` VARCHAR(190) DEFAULT NULL AFTER `job_id`');
    } catch (\Throwable $e) {
        // Column may already exist
    }
}

// ============================================================================
// Migration for version < 3.3.21.5: Change json_array to json
// ============================================================================

// This is handled by general type changes below

// ============================================================================
// Migration for version < 3.3.22.0: Add undo_job_id, create bulk_imported table
// ============================================================================

if (!$columnExists('bulk_import', 'undo_job_id')) {
    $message = new PsrMessage('Adding undo_job_id column to bulk_import.'); // @translate
    $messenger->addNotice($message);
    try {
        $connection->executeStatement('ALTER TABLE bulk_import ADD undo_job_id INT DEFAULT NULL AFTER job_id');
        if (!$foreignKeyExists('bulk_import', 'FK_BD98E8744C276F75')) {
            $connection->executeStatement('ALTER TABLE bulk_import ADD CONSTRAINT FK_BD98E8744C276F75 FOREIGN KEY (undo_job_id) REFERENCES job (id) ON DELETE SET NULL');
        }
    } catch (\Throwable $e) {
        // Continue on error
    }
}

if (!$tableExists('bulk_imported')) {
    $message = new PsrMessage('Creating bulk_imported table.'); // @translate
    $messenger->addNotice($message);
    $sql = <<<'SQL'
CREATE TABLE `bulk_imported` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `job_id` INT NOT NULL,
    `entity_id` INT NOT NULL,
    `entity_name` VARCHAR(190) NOT NULL,
    INDEX IDX_F60E437CBE04EA9 (`job_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
SQL;
    try {
        $connection->executeStatement($sql);
        $connection->executeStatement('ALTER TABLE bulk_imported ADD CONSTRAINT FK_F60E437CBE04EA9 FOREIGN KEY (job_id) REFERENCES job (id)');
    } catch (\Throwable $e) {
        // Table may already exist
    }
}

// Fix column name if old (resource_type -> entity_name)
if ($columnExists('bulk_imported', 'resource_type')) {
    try {
        $connection->executeStatement('ALTER TABLE `bulk_imported` CHANGE `resource_type` `entity_name` VARCHAR(190) NOT NULL');
    } catch (\Throwable $e) {
        // Continue on error
    }
}

// ============================================================================
// Migration for version < 3.3.31.0: Create bulk_mapping table, update vocabulary
// ============================================================================

if (!$tableExists('bulk_mapping')) {
    $message = new PsrMessage('Creating bulk_mapping table.'); // @translate
    $messenger->addNotice($message);
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
SQL;
    try {
        $connection->executeStatement($sql);
        $connection->executeStatement('ALTER TABLE `bulk_mapping` ADD CONSTRAINT FK_7DA823507E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL');
    } catch (\Throwable $e) {
        // Table may already exist
    }
}

// ============================================================================
// Migration for version < 3.3.33.1: Add config column to bulk_importer
// ============================================================================

if (!$columnExists('bulk_importer', 'config')) {
    $message = new PsrMessage('Adding config column to bulk_importer.'); // @translate
    $messenger->addNotice($message);
    try {
        $connection->executeStatement("ALTER TABLE bulk_importer ADD `config` LONGTEXT NOT NULL COMMENT '(DC2Type:json)' AFTER `label`");
        $connection->executeStatement("UPDATE bulk_importer SET `config` = '{}'");
    } catch (\Throwable $e) {
        // Column may already exist
    }
}

// ============================================================================
// Migration for version < 3.4.46: Update vocabulary curation
// ============================================================================

$terms = [
    'curation:dateStart' => 'curation:start',
    'curation:dateEnd' => 'curation:end',
];
foreach ($terms as $propertyOld => $propertyNew) {
    try {
        $propertyOldResult = $api->searchOne('properties', ['term' => $propertyOld])->getContent();
        $propertyNewResult = $api->searchOne('properties', ['term' => $propertyNew])->getContent();
        if ($propertyOldResult && $propertyNewResult) {
            $connection->executeStatement('UPDATE `value` SET `property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                'property_id_1' => $propertyOldResult->id(),
                'property_id_2' => $propertyNewResult->id(),
            ]);
            $connection->executeStatement('UPDATE `resource_template_property` SET `property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                'property_id_1' => $propertyOldResult->id(),
                'property_id_2' => $propertyNewResult->id(),
            ]);
            try {
                $connection->executeStatement('UPDATE `resource_template_property_data` SET `resource_template_property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                    'property_id_1' => $propertyOldResult->id(),
                    'property_id_2' => $propertyNewResult->id(),
                ]);
            } catch (\Throwable $e) {
                // Table may not exist
            }
            $connection->executeStatement('DELETE FROM `property` WHERE id = :property_id;', [
                'property_id' => $propertyNewResult->id(),
            ]);
        }
    } catch (\Throwable $e) {
        // Properties may not exist
    }
}

// Update vocabulary curation - separate statements for compatibility.
try {
    $connection->executeStatement(<<<'SQL'
        UPDATE `vocabulary`
        SET `comment` = 'Generic and common properties that are useful in Omeka for the curation of resources. The use of more common or more precise ontologies is recommended when it is possible.'
        WHERE `prefix` = 'curation'
        SQL);
} catch (\Throwable $e) {
    // Vocabulary may not exist.
}
try {
    $connection->executeStatement(<<<'SQL'
        UPDATE `property`
        JOIN `vocabulary` on `vocabulary`.`id` = `property`.`vocabulary_id`
        SET
            `property`.`local_name` = 'start',
            `property`.`label` = 'Start',
            `property`.`comment` = 'A start related to the resource, for example the start of an embargo.'
        WHERE
            `vocabulary`.`prefix` = 'curation'
            AND `property`.`local_name` = 'dateStart'
        SQL);
} catch (\Throwable $e) {
    // Property may not exist.
}
try {
    $connection->executeStatement(<<<'SQL'
        UPDATE `property`
        JOIN `vocabulary` on `vocabulary`.`id` = `property`.`vocabulary_id`
        SET
            `property`.`local_name` = 'end',
            `property`.`label` = 'End',
            `property`.`comment` = 'A end related to the resource, for example the end of an embargo.'
        WHERE
            `vocabulary`.`prefix` = 'curation'
            AND `property`.`local_name` = 'dateEnd'
        SQL);
} catch (\Throwable $e) {
    // Property may not exist.
}

// ============================================================================
// Migration for version < 3.4.48: Restructure bulk_importer table
// ============================================================================

$hasOldStructure = $columnExists('bulk_importer', 'reader_config')
    || $columnExists('bulk_importer', 'reader_class')
    || $columnExists('bulk_importer', 'processor_config')
    || $columnExists('bulk_importer', 'processor_class');

if ($hasOldStructure) {
    $message = new PsrMessage('Migrating bulk_importer table structure (3.4.48).'); // @translate
    $messenger->addNotice($message);

    $hasReaderClass = $columnExists('bulk_importer', 'reader_class');
    $hasReaderConfig = $columnExists('bulk_importer', 'reader_config');
    $hasProcessorClass = $columnExists('bulk_importer', 'processor_class');
    $hasProcessorConfig = $columnExists('bulk_importer', 'processor_config');

    // Prepare for migration
    if ($hasReaderConfig) {
        $connection->executeStatement("UPDATE `bulk_importer` SET `reader_config` = '[]' WHERE `reader_config` IS NULL OR `reader_config` = '' OR `reader_config` = '{}'");
    }
    if ($hasProcessorConfig) {
        $connection->executeStatement("UPDATE `bulk_importer` SET `processor_config` = '[]' WHERE `processor_config` IS NULL OR `processor_config` = '' OR `processor_config` = '{}'");
    }
    $connection->executeStatement("UPDATE `bulk_importer` SET `config` = '[]' WHERE `config` IS NULL OR `config` = '' OR `config` = '{}'");

    // Merge configs
    if ($hasReaderConfig && $hasProcessorConfig) {
        $sql = <<<'SQL'
UPDATE `bulk_importer`
SET `config` = CONCAT(
    '{',
        '"importer":', `config`,
        ',"reader":', `reader_config`,
        ',"mapper":[]',
        ',"processor":', `processor_config`,
    '}'
)
SQL;
        $connection->executeStatement($sql);
    }

    // Handle null labels
    $connection->executeStatement("UPDATE `bulk_importer` SET `label` = '-' WHERE `label` IS NULL");

    // Handle null reader_class/processor_class
    if ($hasReaderClass) {
        $connection->executeStatement("UPDATE `bulk_importer` SET `reader_class` = '' WHERE `reader_class` IS NULL");
    }
    if ($hasProcessorClass) {
        $connection->executeStatement("UPDATE `bulk_importer` SET `processor_class` = '' WHERE `processor_class` IS NULL");
    }

    // Add mapper column if not exists
    if (!$columnExists('bulk_importer', 'mapper')) {
        if (!$columnExists('bulk_importer', 'reader')) {
            if ($hasReaderClass) {
                $connection->executeStatement("ALTER TABLE `bulk_importer` CHANGE `reader_class` `reader` VARCHAR(190) NOT NULL");
                $hasReaderClass = false; // Already renamed
            } else {
                $connection->executeStatement("ALTER TABLE `bulk_importer` ADD `reader` VARCHAR(190) NOT NULL DEFAULT '' AFTER `label`");
            }
        }
        $connection->executeStatement("ALTER TABLE `bulk_importer` ADD `mapper` VARCHAR(190) DEFAULT NULL AFTER `reader`");
    }

    // Handle processor column
    if (!$columnExists('bulk_importer', 'processor')) {
        if ($hasProcessorClass) {
            $connection->executeStatement("ALTER TABLE `bulk_importer` CHANGE `processor_class` `processor` VARCHAR(190) NOT NULL");
            $hasProcessorClass = false; // Already renamed
        } else {
            $connection->executeStatement("ALTER TABLE `bulk_importer` ADD `processor` VARCHAR(190) NOT NULL DEFAULT '' AFTER `mapper`");
        }
    }

    // Drop old columns
    if ($columnExists('bulk_importer', 'reader_config')) {
        $connection->executeStatement("ALTER TABLE `bulk_importer` DROP `reader_config`");
    }
    if ($columnExists('bulk_importer', 'processor_config')) {
        $connection->executeStatement("ALTER TABLE `bulk_importer` DROP `processor_config`");
    }
    if ($hasReaderClass && $columnExists('bulk_importer', 'reader_class')) {
        $connection->executeStatement("ALTER TABLE `bulk_importer` DROP `reader_class`");
    }
    if ($hasProcessorClass && $columnExists('bulk_importer', 'processor_class')) {
        $connection->executeStatement("ALTER TABLE `bulk_importer` DROP `processor_class`");
    }

    // Set mapping "manual" for spreadsheet readers
    $sql = <<<'SQL'
UPDATE `bulk_importer`
SET `mapper` = "manual"
WHERE `reader` LIKE "%CsvReader"
    OR `reader` LIKE "%TsvReader"
    OR `reader` LIKE "%OpenDocumentSpreadsheetReader"
    OR `reader` LIKE "%SpreadsheetReader"
SQL;
    $connection->executeStatement($sql);

    // Move mapping config to mapper
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
}

// ============================================================================
// Migration for version < 3.4.48: Restructure bulk_import table
// ============================================================================

$hasOldImportStructure = $columnExists('bulk_import', 'reader_params')
    || $columnExists('bulk_import', 'processor_params');

if ($hasOldImportStructure) {
    $message = new PsrMessage('Migrating bulk_import table structure (3.4.48).'); // @translate
    $messenger->addNotice($message);

    $hasReaderParams = $columnExists('bulk_import', 'reader_params');
    $hasProcessorParams = $columnExists('bulk_import', 'processor_params');

    // Add params column if not exists
    if (!$columnExists('bulk_import', 'params')) {
        $connection->executeStatement("ALTER TABLE `bulk_import` ADD `params` LONGTEXT NOT NULL COMMENT '(DC2Type:json)'");
        $connection->executeStatement("UPDATE `bulk_import` SET `params` = '[]'");
    }

    if ($hasReaderParams) {
        $connection->executeStatement("UPDATE `bulk_import` SET `reader_params` = '[]' WHERE `reader_params` IS NULL OR `reader_params` = '' OR `reader_params` = '{}'");
    }
    if ($hasProcessorParams) {
        $connection->executeStatement("UPDATE `bulk_import` SET `processor_params` = '[]' WHERE `processor_params` IS NULL OR `processor_params` = '' OR `processor_params` = '{}'");
    }
    $connection->executeStatement("UPDATE `bulk_import` SET `params` = '[]' WHERE `params` IS NULL OR `params` = '' OR `params` = '{}'");

    // Merge params
    if ($hasReaderParams && $hasProcessorParams) {
        $sql = <<<'SQL'
UPDATE `bulk_import`
SET `params` = CONCAT(
    '{',
        '"reader":', `reader_params`,
        ',"mapping":[]',
        ',"processor":', `processor_params`,
    '}'
)
SQL;
        $connection->executeStatement($sql);
    }

    // Replace mapper with mapping
    $connection->executeStatement("UPDATE `bulk_import` SET `params` = REPLACE(`params`, ',\"mapper\":[', ',\"mapping\":[')");

    // Drop old columns
    if ($columnExists('bulk_import', 'reader_params')) {
        $connection->executeStatement("ALTER TABLE `bulk_import` DROP `reader_params`");
    }
    if ($columnExists('bulk_import', 'processor_params')) {
        $connection->executeStatement("ALTER TABLE `bulk_import` DROP `processor_params`");
    }

    // Move processor mapping into mapping
    $qb = $connection->createQueryBuilder();
    $qb
        ->select('bulk_import.id', 'bulk_import.params')
        ->from('bulk_import', 'bulk_import')
        ->orderBy('bulk_import.id', 'asc');
    $importParameters = $connection->executeQuery($qb)->fetchAllKeyValue();
    foreach ($importParameters as $id => $importParams) {
        $importParams = json_decode($importParams, true);
        if (!is_array($importParams)) {
            $importParams = [];
        }
        $mappingMapper = $importParams['mapping'] ?? [];
        $processorMapping = isset($importParams['processor']) && is_array($importParams['processor'])
            ? ($importParams['processor']['mapping'] ?? [])
            : [];
        $importParams['mapping'] = $mappingMapper ?: $processorMapping;
        if (isset($importParams['processor']) && is_array($importParams['processor'])) {
            unset($importParams['processor']['mapping']);
        }
        $connection->executeStatement(
            'UPDATE `bulk_import` SET `params` = :params WHERE `id` = :id',
            [
                'params' => json_encode($importParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => $id,
            ]
        );
    }
}

// ============================================================================
// Ensure correct column types for current schema
// ============================================================================

try {
    $connection->executeStatement("ALTER TABLE `bulk_importer` CHANGE `label` `label` VARCHAR(190) NOT NULL");
    $connection->executeStatement("ALTER TABLE `bulk_importer` CHANGE `reader` `reader` VARCHAR(190) NOT NULL");
    $connection->executeStatement("ALTER TABLE `bulk_importer` CHANGE `processor` `processor` VARCHAR(190) NOT NULL");
    $connection->executeStatement("ALTER TABLE `bulk_importer` CHANGE `config` `config` LONGTEXT NOT NULL COMMENT '(DC2Type:json)'");
    $connection->executeStatement("ALTER TABLE `bulk_import` CHANGE `params` `params` LONGTEXT NOT NULL COMMENT '(DC2Type:json)'");
} catch (\Throwable $e) {
    // Continue on error
}

// ============================================================================
// Add default importers if missing
// ============================================================================

$user = $services->get('Omeka\AuthenticationService')->getIdentity();

require_once dirname(__DIR__, 2) . '/src/Entity/Import.php';
require_once dirname(__DIR__, 2) . '/src/Entity/Importer.php';

// Check which importers already exist
$existingLabels = [];
try {
    $existingLabels = $connection->executeQuery('SELECT `label` FROM `bulk_importer`')->fetchFirstColumn();
} catch (\Throwable $e) {
    // Table may have issues
}

$filenames = [
    'xml - mets.php',
    'xml - mods.php',
    'xml - ead.php',
    'csv - assets.php',
    'ods - assets.php',
    'tsv - assets.php',
];
$inflector = \Doctrine\Inflector\InflectorFactory::create()->build();
foreach ($filenames as $filename) {
    $filepath = dirname(__DIR__) . '/importers/' . $filename;
    if (!file_exists($filepath)) {
        continue;
    }
    $data = include $filepath;
    $label = $data['label'] ?? $data['o:label'] ?? '';
    // Skip if importer with same label already exists
    if (in_array($label, $existingLabels)) {
        continue;
    }
    $data['o:owner'] = $user;
    $entity = new \BulkImport\Entity\Importer();
    foreach ($data as $key => $value) {
        $posColon = strpos((string) $key, ':');
        $keyName = $posColon === false ? $key : substr((string) $key, $posColon + 1);
        $method = 'set' . ucfirst($inflector->camelize($keyName));
        if (method_exists($entity, $method)) {
            $entity->$method($value);
        }
    }
    $entityManager->persist($entity);
}

try {
    $entityManager->flush();
} catch (\Throwable $e) {
    // Continue on error
}

// ============================================================================
// Check and create required directories
// ============================================================================

$basePath = $config['file_store']['local']['base_path'] ?? (OMEKA_PATH . '/files');

if (!$this->checkDestinationDir($basePath . '/xsl')) {
    $message = new PsrMessage(
        'The directory "{directory}" is not writeable.', // @translate
        ['directory' => $basePath . '/xsl']
    );
    $messenger->addWarning($message);
}

if (!$this->checkDestinationDir($basePath . '/bulk_import')) {
    $message = new PsrMessage(
        'The directory "{directory}" is not writeable.', // @translate
        ['directory' => $basePath . '/bulk_import']
    );
    $messenger->addWarning($message);
}

// ============================================================================
// Final message
// ============================================================================

$message = new PsrMessage(
    'Consolidated upgrade completed successfully. The module has been upgraded from version {old_version} to the current structure.', // @translate
    ['old_version' => $oldVersion]
);
$messenger->addSuccess($message);
