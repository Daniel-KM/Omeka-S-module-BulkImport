<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Laminas\Validator\EmailAddress;
use Omeka\Entity\User;
use Omeka\Entity\UserSetting;

trait UserTrait
{
    /**
     * Create users from a source.
     */
    protected function prepareUsers(): void
    {
        // Prepare the list of users from the source.
        // Create empty users and keeps the mapping of ids.
    }

    protected function fillUsers(): void
    {
    }

    /**
     * Unicity of imported ids, emails, and usernames should be checked before.
     *
     * @param iterable $sources Should be countable too.
     *   The source is an json-ld array of Omeka users.
     * @param array|null $ids The ids may be computed early, so use them. When
     * null, use the original source.
     */
    protected function prepareUsersProcess(iterable $sources, ?array $ids = null): void
    {
        $resourceName = 'users';

        if (is_array($ids) && empty($ids)) {
            $this->logger->warn(
                'No ids set to fill {source}.', // @translate
                ['source' => $resourceName]
            );
            return;
        }

        $noIds = is_null($ids);
        if ($noIds) {
            $this->map['users'] = [];
        }

        // Check the size of the import.
        $this->countEntities($sources, $resourceName);
        if ($this->hasError) {
            return;
        }

        if (!$this->totals[$resourceName]) {
            $this->logger->notice(
                'No users importable from source. You may check rights.' // @translate
            );
            return;
        }

        // Keep emails and names to map ids when ids are not provided.
        $emails = [];

        $userEmails = $this->bulk->api()
            ->search('users', [], ['initialize' => false, 'returnScalar' => 'email'])->getContent();
        $userEmails = array_map('mb_strtolower', $userEmails);

        $validator = new EmailAddress();

        // User and Job have doctrine prePersist() and preUpdate(), so it's not
        // possible to keep original created and modified date.
        // So a direct update is done after each flush.
        $updateDates = [];

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sources as $source) {
            ++$index;

            $sourceId = $source['o:id'];

            if ($noIds) {
                $sourceEmail = trim((string) $source['o:email']);
                if (!$sourceEmail) {
                    $cleanName = preg_replace('/[^\da-z][^\da-z_.]*[^\da-z]$/i', '_', $source['o:name']);
                    $source['o:email'] = $cleanName . '@user.net';
                }

                // A previous version was working fine via api.
                // The check of the adapter are done here to avoid exceptions.
                /** @see\Omeka\Api\Adapter\UserAdapter::validateEntity */
                $sourceName = trim((string) $source['o:name']);
                if (!strlen($sourceName)) {
                    $source['o:name'] = $source['o:email'];
                }

                // Check email, since it should be well formatted and unique.
                if (!$validator->isValid($source['o:email'])) {
                    $cleanName = preg_replace('/[^\da-z]/i', '_', $source['o:name']);
                    $prefix = $source['o:id'] ? $source['o:id'] : substr(bin2hex(\Laminas\Math\Rand::getBytes(20)), 0, 3);
                    $source['o:email'] = $prefix . '-' . $cleanName . '@user.net';
                    $this->logger->warn(
                        'The email "{email}" is not valid, so it was renamed too "{email2}".', // @translate
                        ['email' => $sourceEmail, 'email2' => $source['o:email']]
                    );
                }

                $email = mb_strtolower($source['o:email']);
                $emails[$sourceId] = $email;

                // The user already exists in the database.
                // Check for non-empty omeka base, or tools that allow multiple
                // time the same email in database, like eprints, greenstone…
                $userId = array_search($email, $userEmails);
                if ($userId) {
                    ++$skipped;
                    $this->map['users'][$sourceId] = [
                        'id' => $userId,
                        'email' => $email,
                    ];
                    continue;
                }
            } else {
                $sourceEmail = $this->map['users'][$sourceId]['id'];
            }

            if (empty($source['o:role'])) {
                $source['o:role'] = empty($this->modules['Guest']) ? 'researcher' : 'guest';
            }

            $result = $this->updateOrCreateUser($source);

            // Prepare the fix for the dates.
            if ($result) {
                ++$created;
                $userCreated = empty($source['o:created']['@value'])
                    ? $this->currentDateTime
                    : (\DateTime::createFromFormat('Y-m-d H:i:s', $source['o:created']['@value']) ?: $this->currentDateTime);
                $userModified = empty($source['o:modified']['@value'])
                    ? null
                    : (\DateTime::createFromFormat('Y-m-d H:i:s', $source['o:modified']['@value']) ?: $this->currentDateTime);
                $updateDates[] = [
                    $source['o:email'],
                    $userCreated->format('Y-m-d H:i:s'),
                    $userModified ? $userModified->format('Y-m-d H:i:s') : null,
                ];
            } else {
                ++$skipped;
            }

            if ($created % self::CHUNK_ENTITIES === 0) {
                if ($this->isErrorOrStop()) {
                    break;
                }
                // Flush created or updated entities and fix dates.
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->updateDates('users', 'email', $updateDates);
                $updateDates = [];
                $this->refreshMainResources();
                $this->logger->notice(
                    '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                    ['count' => $created, 'total' => count($this->map[$resourceName]), 'type' => $resourceName, 'skipped' => $skipped]
                );
            }
        }

        unset($userEmails);

        // Remaining entities and fix for dates.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->updateDates('users', 'email', $updateDates);
        $updateDates = [];
        $this->refreshMainResources();

        if (!$noIds && count($emails)) {
            // Map the ids.
            $sql = <<<SQL
SELECT `user`.`id`, `user`.`email`
FROM `user` AS `user`
WHERE `user`.`email` IN (%s);

SQL;
            $sql = sprintf($sql, '"' . implode('","', $emails) . '"');
            $destEmails = $this->connection->executeQuery($sql)->fetchAllKeyValue();
            $destEmails = array_map('mb_strtolower', $destEmails);
            foreach ($emails as $sourceId => $email) {
                $destId = array_search($email, $destEmails);
                $this->map['users'][$sourceId]['id'] = $destId === false ? null : $destId;
                $this->map['users'][$sourceId]['email'] = $email;
            }
        }

        $this->logger->notice(
            '{total} users ready, {created} created, {skipped} skipped.', // @translate
            ['total' => $index, 'created' => $created, 'skipped' => $skipped]
        );
    }

    /**
     * Fill a fully checked user with a json-ld Omeka user array.
     */
    protected function updateOrCreateUser(array $user): bool
    {
        if (empty($user['o:id'])) {
            unset($user['@id'], $user['o:id']);
            $this->entity = new User();
        } else {
            $this->entity = $this->entityManager->find(User::class, $user['o:id']);
            // It should not occur: empty entity should have been created.
            if (!$this->entity) {
                $this->hasError = true;
                $this->logger->err(
                    'The id #{id} was provided for "{name}", but the entity was not created early.', // @translate
                    ['id' => $user['o:id'], 'name' => 'users']
                );
                return false;
            }
        }

        $userCreated = empty($user['o:created']['@value'])
            ? $this->currentDateTime
            : (\DateTime::createFromFormat('Y-m-d H:i:s', $user['o:created']['@value']) ?: $this->currentDateTime);
        $userModified = empty($user['o:modified']['@value'])
            ? null
            : (\DateTime::createFromFormat('Y-m-d H:i:s', $user['o:modified']['@value']) ?: $this->currentDateTime);

        // Omeka core entities are not fluid.
        $this->entity->setEmail($user['o:email']);
        $this->entity->setName($user['o:name']);
        $this->entity->setRole($user['o:role']);
        $this->entity->setIsActive(!empty($user['o:is_active']));
        $this->entity->setCreated($userCreated);
        // User modified doesn't allow null.
        if ($userModified) {
            $this->entity->setModified($userModified);
        }

        $this->appendUserSettings($user);

        $this->appendUserName($user);

        $this->entityManager->persist($this->entity);

        $this->logger->notice(
            'User {email} has been created with name {name}.', // @translate
            ['email' => $user['o:email'], 'name' => $user['o:name']]
        );

        return true;
    }

    protected function userOrDefaultOwner($id): ?User
    {
        if (empty($id)) {
            return $this->owner;
        }
        if (is_array($id)) {
            $id = $id['o:id'];
        }
        return empty($this->map['users'][$id])
            ? $this->owner
            : $this->entityManager->find(User::class, $this->map['users'][$id]['id']);
    }

    /**
     * @return array|null When id is an object, return its id, else return the
     * mapped user. When id is an array, get its id, then return the mapped
     * user.
     */
    protected function userIdOrDefaultOwner($id): ?int
    {
        if (empty($id)) {
            return $this->ownerId;
        }
        // If it is already user, use it!
        if (is_object($id)) {
            $id = method_exists($id, 'getId')
                ? $id->getId()
                : (method_exists($id, 'id') ? $id->id() : null);
            return $id ?: $this->ownerId;
        }
        if (is_array($id)) {
            $id = $id['o:id'] ?? $id['id'] ?? reset($id);
        }
        return empty($this->map['users'][$id])
            ? $this->ownerId
            : $this->map['users'][$id]['id'];
    }

    /**
     * @return array|null When id is an object, return its id, else return the
     * mapped user. When id is an array, get its id, then return the mapped
     * user.
     */
    protected function userOIdOrDefaultOwner($id): ?array
    {
        if (empty($id)) {
            return $this->ownerOId;
        }
        // If it is already an object, use it!
        if (is_object($id)) {
            $id = method_exists($id, 'getId')
                ? $id->getId()
                : (method_exists($id, 'id') ? $id->id() : null);
            return $id ? ['o:id' => $id] : $this->ownerOId;
        }
        if (is_array($id)) {
            $id = $id['o:id'] ?? $id['id'] ?? reset($id);
        }
        return empty($this->map['users'][$id])
            ? $this->ownerOId
            : ['o:id' => $this->map['users'][$id]['id']];
    }

    /**
     * Prepare user settings entities. No flush.
     *
     * @param array $source
     */
    protected function appendUserSettings(array $source): void
    {
        if (empty($source['o:settings']) || !is_array($source['o:settings'])) {
            return;
        }

        $userSettingRepository = $this->entityManager->getRepository(UserSetting::class);
        foreach ($source['o:settings'] as $name => $value) {
            if (is_null($value)
                || (is_array($value) && !count($value))
                || (!is_array($value) && !strlen((string) $value))
            ) {
                continue;
            }
            $userSetting = $userSettingRepository->findOneBy(['user' => $this->entity, 'id' => $name]);
            // Omeka entities are not fluid.
            if ($userSetting) {
                if ($value === $userSetting->getValue()) {
                    continue;
                }
            } else {
                $userSetting = new UserSetting();
                $userSetting->setId($name);
                $userSetting->setUser($this->entity);
            }
            $userSetting->setValue($value);
            $this->entityManager->persist($userSetting);
        }
    }

    /**
     * Users should be created first.
     *
     * Warning: it works only if the source emails are unique and not renamed.
     *
     * @param iterable $sources
     */
    protected function prepareUsersSettings(iterable $sources): void
    {
        $userSettingRepository = $this->entityManager->getRepository(UserSetting::class);
        $created = 0;
        foreach ($sources as $source) {
            $userId = $this->map['users'][$source['o:id']]['id'];
            $user = $this->entityManager->find(User::class, $userId);
            foreach ($source['o:settings'] ?? [] as $name => $value) {
                if (is_null($value)
                    || (is_array($value) && !count($value))
                    || (!is_array($value) && !strlen((string) $value))
                ) {
                    continue;
                }
                $userSetting = $userSettingRepository->findOneBy(['user' => $user, 'id' => $name]);
                // Omeka entities are not fluid.
                if ($userSetting) {
                    if ($value === $userSetting->getValue()) {
                        continue;
                    }
                } else {
                    $userSetting = new UserSetting();
                    $userSetting->setId($name);
                    $userSetting->setUser($user);
                }
                $userSetting->setValue($value);
                $this->entityManager->persist($userSetting);
                ++$created;

                if ($created % self::CHUNK_ENTITIES === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $this->refreshMainResources();
                }
            }
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }
}
