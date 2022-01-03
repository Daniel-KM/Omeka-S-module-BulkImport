<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Entry\Entry;
use Log\Stdlib\PsrMessage;
use Omeka\Stdlib\ErrorStore;

/**
 * Manage checks of source.
 */
trait CheckTrait
{
    /**
     * Log infos about one entry and conversion to one or multiple resources.
     */
    protected function logCheckedResource(?ArrayObject $resource, ?Entry $entry = null): \BulkImport\Processor\Processor
    {
        if (!$resource && !$entry) {
            $this->logger->log(\Laminas\Log\Logger::INFO,
                'Index #{index}: Skipped', // @translate
                ['index' => $this->indexResource]
            );
            return $this;
        }

        if ($entry && $entry->isEmpty()) {
            $this->logger->log(\Laminas\Log\Logger::NOTICE,
                'Index #{index}: Empty source', // @translate
                ['index' => $this->indexResource]
            );
            return $this;
        }

        if (!$resource) {
            $this->logger->log(\Laminas\Log\Logger::ERR,
                'Index #{index}: Source cannot be converted', // @translate
                ['index' => $this->indexResource]
            );
            return $this;
        }

        if ($resource['errorStore']->hasErrors()) {
            $this->logger->log(\Laminas\Log\Logger::ERR,
                'Index #{index}: Error processing data.', // @translate
                ['index' => $this->indexResource]
            );
        } elseif ($resource['warningStore']->hasErrors()) {
            $this->logger->log(\Laminas\Log\Logger::WARN,
                'Index #{index}: Warning about data processed.', // @translate
                ['index' => $this->indexResource]
            );
        } elseif ($resource['infoStore']->hasErrors()) {
            $this->logger->log(\Laminas\Log\Logger::INFO,
                'Index #{index}: Info about data processed.', // @translate
                ['index' => $this->indexResource]
            );
        } else {
            return $this;
        }

        $logging = function($message): array {
            if (is_string($message)) {
                return [$message, []];
            }
            if ($message instanceof \Log\Stdlib\PsrMessage) {
                return [$message->getMessage(), $message->getContext()];
            }
            return [(string) $message, []];
        };

        $messages = $this->flatMessages($resource['errorStore']->getErrors());
        foreach ($messages as $messagess) {
            foreach ($messagess as $message) {
                [$msg, $context] = $logging($message);
                $this->logger->log(\Laminas\Log\Logger::ERR, $msg, $context);
            }
        }

        $messages = $this->flatMessages($resource['warningStore']->getErrors());
        foreach ($messages as $messagess) {
            foreach ($messagess as $message) {
                [$msg, $context] = $logging($message);
                $this->logger->log(\Laminas\Log\Logger::WARN, $msg, $context);
            }
        }

        $messages = $this->flatMessages($resource['infoStore']->getErrors());
        foreach ($messages as $messagess) {
            foreach ($messagess as $message) {
                [$msg, $context] = $logging($message);
                $this->logger->log(\Laminas\Log\Logger::INFO, $msg, $context);
            }
        }

        return $this;
    }

    /**
     * Error store is recursive, so merge all sub-levels into a flat array.
     *
     * Normally useless, but some message may be added indirectly.
     *
     * @see \Omeka\Stdlib\ErrorStore::mergeErrors()
     * @todo Move this into a new class wrapping or replacing ErrorStore.
     */
    protected function flatMessages($messages): array
    {
        // The keys of the first level should be kept, but not the sub-ones.
        $flatArray = [];
        foreach ($messages as $key => $message) {
            if (is_array($message)) {
                array_walk_recursive($message, function($data) use(&$flatArray, $key) {
                    $flatArray[$key][] = $data;
                });
            } else {
                $flatArray[$key][] = $message;
            }
        }
        return $flatArray;
    }
}
