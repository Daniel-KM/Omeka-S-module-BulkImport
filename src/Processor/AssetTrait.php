<?php declare(strict_types=1);

namespace BulkImport\Processor;

trait AssetTrait
{
    protected function prepareAssets(): void
    {
        // Assets are managed first because they are not resources and resources
        // may use thumbnails.
        // Create empty assets and keeps the mapping of ids.
    }

    protected function fillAssets(): void
    {
    }

    protected function prepareAssetsProcess(iterable $sourceAssets): void
    {
        // Check the size of the import.
        $this->countEntities($sourceAssets, 'assets');
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
        foreach ($sourceAssets as $resource) {
            $resourceId = (int) $resource['o:id'];
            // To avoid collisions with a failed import, prepend the timestamp.
            $this->map['assets'][$resourceId] = $timestamp . '-' . $resourceId;
            // Remove extension manually because module Ebook uses a
            // specific storage id.
            $extension = pathinfo($resource['o:filename'], PATHINFO_EXTENSION);
            $assetStorages[$resourceId] = mb_strlen($extension)
                ? mb_substr($resource['o:filename'], 0, -mb_strlen($extension) - 1)
                : $resource['o:filename'];
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
        $existingAssets = array_column($this->connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC), 'd');

        $sql = '';
        // Save the ids as storage, it should be unique anyway, except
        // in case of reimport.
        $toCreate = array_diff_key($this->map['assets'], array_flip($existingAssets));
        foreach (array_chunk($toCreate, self::CHUNK_RECORD_IDS) as $chunk) {
            $sql .= 'INSERT INTO `asset` (`name`,`media_type`,`storage_id`) VALUES("","","' . implode('"),("","","', $chunk) . '");' . "\n";
        }
        if ($sql) {
            $this->connection->query($sql);
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
        // Fetch by key pair is not supported by doctrine 2.0.
        $this->map['assets'] = array_column($this->connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC), 'd', 's');

        $this->logger->notice(
            '{total} resources "{type}" have been created.', // @translate
            ['total' => count($this->map['assets']), 'type' => 'assets']
        );
    }

    protected function fillAssetsProcess(iterable $sourceAssets): void
    {
        $this->refreshOwner();

        /** @var \Omeka\Api\Adapter\AssetAdapter $adapter */
        $adapter = $this->adapterManager->get('assets');
        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sourceAssets as $resource) {
            ++$index;
            $resourceId = $resource['o:id'];
            // Some new resources created since first loop.
            if (!isset($this->map['assets'][$resourceId])) {
                ++$skipped;
                $this->logger->notice(
                    'Skipped resource "{type}" #{source_id} existing or added in source.', // @translate
                    ['type' => 'asset', 'source_id' => $resourceId]
                );
                continue;
            }

            if (($pos = mb_strrpos($resource['o:filename'], '.')) === false) {
                ++$skipped;
                $this->logger->warn(
                    'Asset {id} has no filename or no extension.', // @translate
                    ['id' => $resourceId]
                );
                continue;
            }

            // Api can't be used because the asset should be downloaded locally.
            // unset($resource['@id'], $resource['o:id']);
            // $response = $this->api()->create('assets', $resource);

            // TODO Keep the original storage id of assets (so check existing one as a whole).
            // $storageId = substr($resource['o:filename'], 0, $pos);
            // @see \Omeka\File\TempFile::getStorageId()
            $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
            $extension = substr($resource['o:filename'], $pos + 1);

            $result = $this->fetchUrl('asset', $resource['o:name'], $resource['o:filename'], $storageId, $extension, $resource['o:asset_url']);
            if ($result['status'] !== 'success') {
                ++$skipped;
                $this->logger->err($result['message']);
                continue;
            }

            $this->entity = $this->entityManager->find(\Omeka\Entity\Asset::class, $this->map['assets'][$resourceId]);

            // Omeka entities are not fluid.
            $this->entity->setOwner($this->userOrDefaultOwner($resource['o:owner']));
            $this->entity->setName($resource['o:name']);
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
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->refreshOwner();
                $this->logger->notice(
                    '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                    ['count' => $created, 'total' => count($this->map['assets']), 'type' => 'asset', 'skipped' => $skipped]
                );
            }
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshOwner();

        $this->logger->notice(
            '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
            ['count' => $created, 'total' => $index, 'type' => 'asset', 'skipped' => $skipped]
        );
    }
}
