<?php declare(strict_types=1);

namespace BulkImport\Processor;

trait AssetTrait
{
    protected function prepareAssetsProcess(iterable $sources): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping['assets']['source'])) {
            return;
        }

        $keyId = $this->mapping['assets']['key_id'];

        // Check the size of the import.
        $this->countEntities($sources, 'assets');
        if ($this->hasError) {
            return;
        }

        $this->logger->notice(
            'Preparation of {total} resources "{type}".', // @translate
            ['total' => $this->totals['assets'], 'type' => 'assets']
        );

        // Get the list of ids and prepare fake storage ids.
        $assetStorages = [];
        $this->map['assets'] = [];
        $timestamp = time();
        foreach ($sources as $source) {
            $sourceId = (int) $source[$keyId];
            // To avoid collisions with a failed import, prepend the timestamp.
            $this->map['assets'][$sourceId] = $timestamp . '-' . $sourceId;
            // Remove extension manually because module Ebook uses a
            // specific storage id.
            $extension = pathinfo($source['o:filename'], PATHINFO_EXTENSION);
            $assetStorages[$sourceId] = mb_strlen($extension)
                ? mb_substr($source['o:filename'], 0, -mb_strlen($extension) - 1)
                : $source['o:filename'];
        }
        if (!count($assetStorages)) {
            return;
        }

        // Create the ids.

        $storageIds = implode(',', array_map([$this->connection, 'quote'], $assetStorages));
        // Get existing duplicates for reimport (same storage id).
        $sql = <<<SQL
SELECT `asset`.`id` AS `d`
FROM `asset` AS `asset`
WHERE `asset`.`storage_id` IN ($storageIds);
SQL;
        $existingAssets = array_map('intval', $this->connection->executeQuery($sql)->fetchFirstColumn());

        $sql = '';
        // Save the ids as storage, it should be unique anyway, except in case
        // of reimport.
        $toCreate = array_diff_key($this->map['assets'], array_flip($existingAssets));
        foreach (array_chunk($toCreate, self::CHUNK_RECORD_IDS) as $chunk) {
            $sql .= 'INSERT INTO `asset` (`name`,`media_type`,`storage_id`) VALUES("","","' . implode('"),("","","', $chunk) . '");' . "\n";
        }
        if ($sql) {
            $this->connection->executeStatement($sql);
        }

        // Get the mapping of source and destination ids.
        $sql = <<<SQL
SELECT SUBSTRING(`asset`.`storage_id`, 12) AS `s`, `asset`.`id` AS `d`
FROM `asset` AS `asset`
WHERE `asset`.`name` = ""
    AND `asset`.`media_type` = ""
    AND (`asset`.`extension` IS NULL OR `asset`.`extension` = "")
    AND `asset`.`owner_id` IS NULL
    AND `asset`.`storage_id` LIKE "$timestamp-%";
SQL;
        $this->map['assets'] = array_map('intval', $this->connection->executeQuery($sql)->fetchAllKeyValue());

        $this->logger->notice(
            '{total} resources "{type}" have been created.', // @translate
            ['total' => count($this->map['assets']), 'type' => 'assets']
        );
    }

    protected function fillAssetsProcess(iterable $sources): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping['assets']['source'])) {
            return;
        }

        $this->refreshMainResources();

        $keyId = $this->mapping['assets']['key_id'];

        /** @var \Omeka\Api\Adapter\AssetAdapter $adapter */
        $adapter = $this->adapterManager->get('assets');

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sources as $source) {
            ++$index;
            $sourceId = $source[$keyId];
            // Some new resources created since first loop.
            if (!isset($this->map['assets'][$sourceId])) {
                ++$skipped;
                $this->logger->notice(
                    'Skipped resource "{type}" #{source_id} existing or added in source.', // @translate
                    ['type' => 'asset', 'source_id' => $sourceId]
                );
                continue;
            }

            if (($pos = mb_strrpos($source['o:filename'], '.')) === false) {
                ++$skipped;
                $this->logger->warn(
                    'Asset {id} has no filename or no extension.', // @translate
                    ['id' => $sourceId]
                );
                continue;
            }

            // Api can't be used because the asset should be downloaded locally.
            // unset($source['@id'], $source[$keyId]);
            // $response = $this->bulk->api()->create('assets', $source);

            // TODO Keep the original storage id of assets (so check existing one as a whole).
            // $storageId = substr($source['o:filename'], 0, $pos);
            // @see \Omeka\File\TempFile::getStorageId()
            $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
            $extension = substr($source['o:filename'], $pos + 1);

            $result = $this->fetchUrl('asset', $source['o:name'], $source['o:filename'], $storageId, $extension, $source['o:asset_url']);
            if ($result['status'] !== 'success') {
                ++$skipped;
                $this->logger->err($result['message']);
                continue;
            }

            $this->entity = $this->entityManager->find(\Omeka\Entity\Asset::class, $this->map['assets'][$sourceId]);

            // Omeka entities are not fluid.
            $this->entity->setOwner($this->userOrDefaultOwner($source['o:owner']));
            $this->entity->setName($source['o:name']);
            $this->entity->setMediaType($result['data']['media_type']);
            $this->entity->setStorageId($storageId);
            $this->entity->setExtension($extension);

            $errorStore = new \Omeka\Stdlib\ErrorStore;
            $adapter->validateEntity($this->entity, $errorStore);
            if ($errorStore->hasErrors()) {
                ++$skipped;
                $this->logErrors($this->entity, $errorStore);
                continue;
            }

            // TODO Trigger an event for modules (or manage them here).

            $this->entityManager->persist($this->entity);
            ++$created;

            if ($created % self::CHUNK_ENTITIES === 0) {
                if ($this->isErrorOrStop()) {
                    break;
                }
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->refreshMainResources();
                $this->logger->notice(
                    '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                    ['count' => $created, 'total' => count($this->map['assets']), 'type' => 'asset', 'skipped' => $skipped]
                );
            }
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        $this->logger->notice(
            '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
            ['count' => $created, 'total' => $index, 'type' => 'asset', 'skipped' => $skipped]
        );
    }
}
