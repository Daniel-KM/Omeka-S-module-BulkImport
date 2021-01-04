<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Laminas\Validator\EmailAddress;
use Omeka\Entity\User;

trait UserTrait
{
    /**
     * Create users from a source.
     */
    protected function prepareUsers(): void
    {
        // Prepare the list of users from the source.
    }

    /**
     * Unicity of imported emails should be checked before.
     *
     * @param iterable $sourceUsers Should be countable too.
     */
    protected function prepareUsersProcess(iterable $sourceUsers): void
    {
        $this->map['users'] = [];

        if ((is_array($sourceUsers) && !count($sourceUsers))
            || (!is_array($sourceUsers) && !$sourceUsers->count())
        ) {
            $this->logger->notice(
                'No users importable from source. You may check rights.' // @translate
            );
            return;
        }

        $users = $this->api()
            ->search('users', [], ['initialize' => false, 'returnScalar' => 'email'])->getContent();
        $users = array_map('mb_strtolower', $users);

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sourceUsers as $sourceUser) {
            ++$index;
            $sourceId = $sourceUser['o:id'];
            $sourceEmail = trim((string) $sourceUser['o:email']);
            if (!$sourceEmail) {
                $cleanName = preg_replace('/[^\da-z]/i', '_', $sourceUser['o:name']);
                $sourceUser['o:email'] = $cleanName . '@user.net';
            }

            // Here, we use the standard api, but the check of the adapter are
            // done here to avoid exceptions.
            /** @see\Omeka\Api\Adapter\UserAdapter::validateEntity */

            $sourceName = trim((string) $sourceUser['o:name']);
            if (!strlen($sourceName)) {
                $sourceUser['o:name'] = $sourceUser['o:email'];
            }

            // Check email, since it should be well formatted and unique.
            $validator = new EmailAddress();
            if (!$validator->isValid($sourceUser['o:email'])) {
                $cleanName = preg_replace('/[^\da-z]/i', '_', $sourceUser['o:name']);
                $prefix = $sourceUser['o:id'] ? $sourceUser['o:id'] : substr(bin2hex(\Laminas\Math\Rand::getBytes(20)), 0, 3);
                $sourceUser['o:email'] = $prefix . '-' . $cleanName . '@user.net';
                $this->logger->warn(
                    'The email "{email}" is not valid, so it was renamed too "{email2}".', // @translate
                    ['email' => $sourceEmail, 'email2' => $sourceUser['o:email']]
                );
            }

            if (!strlen($sourceUser['o:role'])) {
                $sourceUser['o:role'] = empty($this->modules['Guest']) ? 'researcher' : 'guest';
            }

            if ($userId = array_search(mb_strtolower($sourceUser['o:email']), $users)) {
                ++$skipped;
                $this->map['users'][$sourceId] = $userId;
                continue;
            }

            unset($sourceUser['@id'], $sourceUser['o:id']);

            $response = $this->api()->create('users', $sourceUser, [], ['responseContent' => 'resource']);
            if (!$response) {
                $this->hasError = true;
                $this->logger->err(
                    'Unable to create user "{email}".', // @translate
                    ['email' => $sourceUser['o:email']]
                );
                return;
            }
            $this->logger->notice(
                'User {email} has been created with name {name}.', // @translate
                ['email' => $sourceUser['o:email'], 'name' => $sourceUser['o:name']]
            );
            ++$created;

            $this->map['users'][$sourceId] = $response->getContent()->getId();
        }

        $this->logger->notice(
            '{total} users ready, {created} created, {skipped} skipped.', // @translate
            ['total' => $index, 'created' => $created, 'skipped' => $skipped]
        );
    }
}
