<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Laminas\Validator\EmailAddress;
use Omeka\Entity\User;
use Omeka\Entity\UserSetting;
use UserNames\Entity\UserNames;

trait UserTrait
{
    /**
     * Unicity of imported ids, emails, and usernames should be checked before.
     *
     * @param iterable $sources Should be countable too.
     *   The source is an json-ld array of Omeka users.
     */
    protected function prepareUsersProcess(iterable $sources): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping['users']['source'])) {
            return;
        }

        // The maps is done early in order to keep original ids when possible.
        $this->map['users'] = [];

        $existingUsers = $this->apiManager->search('users', [], ['initialize' => false, 'returnScalar' => 'email'])->getContent();
        $existingUsernames = !empty($this->modulesActive['UserNames'])
            ? $this->apiManager->search('usernames', [], ['initialize' => false, 'returnScalar' => 'userName'])->getContent()
            : null;

        // Emails and user names should be lower-cased to be checked.
        $existingUsers = array_map('mb_strtolower', $existingUsers);
        $existingUsernames = is_null($existingUsernames) ? null :  array_map('mb_strtolower', $existingUsernames);

        $validator = new EmailAddress();

        $importId = $this->job->getImportId();

        $emails = [];
        $index = 0;
        foreach ($sources as $source) {
            ++$index;

            $keyUserId = $this->mapping['users']['key_id'] ?? 'id';

            $originalUserId = $source[$keyUserId] ?? null;
            if (!$originalUserId) {
                $this->hasError = true;
                $this->logger->err(
                    'The user index #{index} has no source id.', // @translate
                    ['index' => $index]
                );
                return;
            }

            $user = $this->prepareUser($source);

            // Manage exceptions (existing users in Omeka).
            // TODO Manage existing users with the same email? Or let createEmptyEntiies() check them.
            if (isset($existingUsers[$originalUserId])) {
                $existingUsers[] = '+';
                end($existingUsers);
                $user['id'] = (int) key($existingUsers);
                $this->logger->warn(
                    'To avoid a conflict, the user #{id} ("{name}") has a new id: #{newid}.', // @translate
                    ['id' => $originalUserId, 'name' => $user['name'] ?? '', 'newid' => $user['id']]
                );
            }

            // Omeka does not require unique UserNames.
            // So the check is done only when the module UserNames is present.
            if (is_array($existingUsernames)) {
                $userName = empty($user['name']) ? '' : mb_strtolower($user['name']);
                if ($userName) {
                    if (in_array($userName, $existingUsernames)) {
                        $newName = $userName . '-' . strtolower($this->randomString(6));
                        $this->logger->warn(
                            'To avoid a conflict, the user #{id} ("{name}") has a new name: {newname}.', // @translate
                            ['id' => $originalUserId, 'name' => $user['name'], 'newname' => $newName]
                        );
                        $userName = $newName;
                        $user['name'] = $newName;
                    }
                    $existingUsernames[] = mb_strtolower($userName);
                }
            }

            $email = mb_strtolower((string) $user['email']);
            if (!$validator->isValid($email)) {
                $cleanName = empty($user['name'])
                    ?$this->randomString(5)
                    : preg_replace('/[^\da-z_-]/i', '_', $user['name']);
                $user['email'] = $user['id'] . '-' . $importId . '-' . $index . '-' . strtolower($cleanName) . '@user.net';
                $this->logger->warn(
                    'The email "{email}" is not valid, so it was renamed to "{email2}".', // @translate
                    ['email' => $email, 'email2' => $user['email']]
                );
                $email = $user['email'];
            }

            if (isset($emails[$email]) || in_array($email, $existingUsers)) {
                $email = $user['id'] . '-' . $importId . '-' . $email;
                $user['email'] = $email;
                $this->logger->warn(
                    'To avoid a conflict, the user #{id} ("{name}") has a new email: {email}".', // @translate
                    ['id' => $originalUserId, 'name' => $user['name'] ?? '', 'email' => $email]
                );
            }
            $emails[$email] = $email;

            $this->map['users'][$originalUserId] = $user;
        }

        $userColumns = [
            'id' => 'id',
            'email' => 'id',
            'name' => 'id',
            'created' => '"' . $this->currentDateTimeFormatted . '"',
            'modified' => 'NULL',
            'password_hash' => '""',
            'role' => '""',
            'is_active' => '0',
        ];

        $this->createEmptyEntities('users', $userColumns, true);

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }

    /**
     * Allows to convert a source into a json-ld Omeka user.
     *
     * Only id, email and name are needed here. Other data are filled later.
     *
     * @todo Use metaMapper().
     */
    protected function prepareUser(array $source): array
    {
        $user = [
            'id' => $source[$this->mapping['users']['key_id'] ?? 'id'] ?? null,
            'email' => $source[$this->mapping['users']['key_email'] ?? 'email'] ?? null,
            'name' => $source[$this->mapping['users']['key_name'] ?? 'name'] ?? null,
        ];
        $user['id'] = $user['id'] ? (int) $user['id'] : null;
        return $user;
    }

    /**
     * @todo Move to sql reader with metaMapper().
     *
     * @param iterable $sources
     */
    protected function fillUsersProcess(iterable $sources): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping['users']['source'])) {
            return;
        }

        $locale = $this->getServiceLocator()->get('Omeka\Settings')->get('locale');
        $role = empty($this->modulesActive['Guest']) ? 'researcher' : 'guest';
        $isActive = true;

        // User and Job have doctrine prePersist() and preUpdate(), so it's not
        // possible to keep original created and modified date.
        // So a direct update is done after each flush.
        $updateDates = [];

        $keyUserId = $this->mapping['users']['key_id'] ?? 'id';
        $keyUserEmail = $this->mapping['users']['key_id'] ?? 'email';
        $keyUserName = $this->mapping['users']['key_name'] ?? null;

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sources as $source) {
            ++$index;

            $source = array_map('trim', array_map('strval', $source));
            $sourceId = $source[$keyUserId];

            // Normally, it should be a useless check, unless the source base
            // was modified during process.
            if (empty($this->map['users'][$sourceId]['id'])
                || empty($this->map['users'][$sourceId]['email'])
                // A user name is not required, but if set, it should be unique.
            ) {
                $this->hasError = true;
                $this->logger->err(
                    'The user #{id} ("{name}" / {email}) has an error: missing data.', // @translate
                    ['id' => $source[$keyUserId], 'name' => $source[$keyUserName], 'email' => $source[$keyUserEmail]]
                );
                return;
            }

            // Use the mapped data in case of fixed duplicates.
            $userId = $this->map['users'][$sourceId]['id'];
            $userEmail = $this->map['users'][$sourceId]['email'];
            $userName = $this->map['users'][$sourceId]['name'] ?? null;

            $userCreated = $this->currentDateTimeFormatted;
            $userModified = null;

            $user = [
                // Keep the source id to simplify next steps and find mapped id.
                // The source id may be updated if duplicate.
                '_source_id' => $sourceId,
                'o:id' => $userId,
                'o:name' => $userEmail,
                'o:email' => $userEmail,
                'o:created' => ['@value' => $userCreated],
                'o:modified' => $userModified ? ['@value' => $userModified] : null,
                'o:role' => $role,
                'o:is_active' => $isActive,
                'o:settings' => [
                    'locale' => $source['lang'] ?: $locale,
                ],
                'o-module-usernames:username' => $userName,
            ];

            $user = $this->fillUser($source, $user);

            $result = $this->updateOrCreateUser($user);

            // Prepare the fix for the dates.
            if ($result) {
                ++$created;
                $userCreated = empty($user['o:created']['@value'])
                    ? $this->currentDateTime
                    : (\DateTime::createFromFormat('Y-m-d H:i:s', $user['o:created']['@value']) ?: $this->currentDateTime);
                $userModified = empty($user['o:modified']['@value'])
                    ? null
                    : (\DateTime::createFromFormat('Y-m-d H:i:s', $user['o:modified']['@value']) ?: $this->currentDateTime);
                $updateDates[] = [
                    $userEmail,
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
                    ['count' => $created, 'total' => count($this->map['users']), 'type' => 'users', 'skipped' => $skipped]
                );
            }
        }

        // Remaining entities and fix for dates.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->updateDates('users', 'email', $updateDates);
        $updateDates = [];
        $this->refreshMainResources();
    }

    /**
     * Complete a user with specific data.
     *
     * Normally, "o:id", "o:email" and "o-module-usernames:username" should not
     * be overridden.
     *
     * @todo Use metaMapper().
     */
    protected function fillUser(array $source, array $user): array
    {
        return $user;
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
            // It should not occur: empty entity should have been created during
            // preparation of the user entities.
            if (!$this->entity) {
                $this->hasError = true;
                $this->logger->err(
                    'The id #{id} was provided for entity"{name}", but the entity was not created early.', // @translate
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
     * Prepare the user name of a user. No flush.
     *
     * The user name should be checked before.
     *
     * @param array $source
     */
    protected function appendUserName(array $source): void
    {
        if (empty($source['o-module-usernames:username'])
            || empty($this->modulesActive['UserNames'])
        ) {
            return;
        }

        // The check should be done before (see eprints processor), but it may
        // be stored by doctrine.
        $userNameRepository = $this->entityManager->getRepository(UserNames::class);
        /** @var \UserNames\Entity\UserNames $userName */
        $userName = $userNameRepository->findOneBy(['userName' => $source['o-module-usernames:username']]);
        if ($userName) {
            $this->logger->notice(
                'The user name "{username}" is already attributed to user #{id}.', // @translate
                ['username' => $source['o-module-usernames:username'], 'id' => $userName->getUser()->getId()]
            );
            return;
        }

        $userName = new UserNames();
        $userName->setUser($this->entity);
        $userName->setUserName($source['o-module-usernames:username']);
        $this->entityManager->persist($userName);
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
