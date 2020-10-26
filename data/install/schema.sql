CREATE TABLE `bulk_import` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `importer_id` INT DEFAULT NULL,
    `job_id` INT DEFAULT NULL,
    `comment` VARCHAR(190) DEFAULT NULL,
    `reader_params` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    `processor_params` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    INDEX IDX_BD98E8747FCFE58E (`importer_id`),
    UNIQUE INDEX UNIQ_BD98E874BE04EA9 (`job_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE `bulk_importer` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `owner_id` INT DEFAULT NULL,
    `label` VARCHAR(190) DEFAULT NULL,
    `reader_class` VARCHAR(190) DEFAULT NULL,
    `reader_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    `processor_class` VARCHAR(190) DEFAULT NULL,
    `processor_config` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)',
    INDEX IDX_2DAF62D7E3C61F9 (`owner_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE `bulk_import` ADD CONSTRAINT FK_BD98E8747FCFE58E FOREIGN KEY (`importer_id`) REFERENCES `bulk_importer` (`id`) ON DELETE SET NULL;
ALTER TABLE `bulk_import` ADD CONSTRAINT FK_BD98E874BE04EA9 FOREIGN KEY (`job_id`) REFERENCES `job` (`id`) ON DELETE SET NULL;
ALTER TABLE `bulk_importer` ADD CONSTRAINT FK_2DAF62D7E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
