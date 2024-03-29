CREATE TABLE `bulk_importer` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `owner_id` INT DEFAULT NULL,
     `label` VARCHAR(190) NOT NULL,
    `reader` VARCHAR(190) NOT NULL,
    `mapper` VARCHAR(190) DEFAULT NULL,
    `processor` VARCHAR(190) NOT NULL,
    `config` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    INDEX IDX_2DAF62D7E3C61F9 (`owner_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

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

CREATE TABLE `bulk_import` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `importer_id` INT DEFAULT NULL,
    `job_id` INT DEFAULT NULL,
    `undo_job_id` INT DEFAULT NULL,
    `comment` VARCHAR(190) DEFAULT NULL,
    `params` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    INDEX IDX_BD98E8747FCFE58E (`importer_id`),
    UNIQUE INDEX UNIQ_BD98E874BE04EA9 (`job_id`),
    UNIQUE INDEX UNIQ_BD98E8744C276F75 (`undo_job_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE `bulk_imported` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `job_id` INT NOT NULL,
    `entity_id` INT NOT NULL,
    `entity_name` VARCHAR(190) NOT NULL,
    INDEX IDX_F60E437CBE04EA9 (`job_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE `bulk_importer` ADD CONSTRAINT FK_2DAF62D7E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
ALTER TABLE `bulk_mapping` ADD CONSTRAINT FK_7DA823507E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
ALTER TABLE `bulk_import` ADD CONSTRAINT FK_BD98E8747FCFE58E FOREIGN KEY (`importer_id`) REFERENCES `bulk_importer` (`id`) ON DELETE SET NULL;
ALTER TABLE `bulk_import` ADD CONSTRAINT FK_BD98E874BE04EA9 FOREIGN KEY (`job_id`) REFERENCES `job` (`id`) ON DELETE SET NULL;
ALTER TABLE `bulk_import` ADD CONSTRAINT FK_BD98E8744C276F75 FOREIGN KEY (`undo_job_id`) REFERENCES `job` (`id`) ON DELETE SET NULL;
ALTER TABLE `bulk_imported` ADD CONSTRAINT FK_F60E437CBE04EA9 FOREIGN KEY (`job_id`) REFERENCES `job` (`id`);
