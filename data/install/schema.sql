SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE `bulk_import` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `importer_id` int(11) DEFAULT NULL,
    `job_id` int(11) DEFAULT NULL,
    `undo_job_id` int(11) DEFAULT NULL,
    `comment` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `reader_params` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '(DC2Type:json)',
    `processor_params` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '(DC2Type:json)',
    PRIMARY KEY (`id`),
    UNIQUE KEY `UNIQ_BD98E874BE04EA9` (`job_id`),
    KEY `IDX_BD98E8747FCFE58E` (`importer_id`),
    CONSTRAINT `FK_BD98E8747FCFE58E` FOREIGN KEY (`importer_id`) REFERENCES `bulk_importer` (`id`) ON DELETE SET NULL,
    CONSTRAINT `FK_BD98E874BE04EA9` FOREIGN KEY (`job_id`) REFERENCES `job` (`id`) ON DELETE SET NULL,
    CONSTRAINT `FK_BD98E8744C276F75` FOREIGN KEY (`undo_job_id`) REFERENCES `job` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `bulk_imported` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `job_id` int(11) NOT NULL,
    `entity_id` int(11) NOT NULL,
    `resource_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (`id`),
    KEY `IDX_F60E437CB6A263D9` (`job_id`),
    CONSTRAINT `FK_F60E437CB6A263D9` FOREIGN KEY (`job_id`) REFERENCES `job` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `bulk_importer` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `owner_id` int(11) DEFAULT NULL,
    `label` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `reader_class` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `reader_config` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '(DC2Type:json)',
    `processor_class` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `processor_config` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '(DC2Type:json)',
    PRIMARY KEY (`id`),
    KEY `IDX_2DAF62D7E3C61F9` (`owner_id`),
    CONSTRAINT `FK_2DAF62D7E3C61F9` FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;
