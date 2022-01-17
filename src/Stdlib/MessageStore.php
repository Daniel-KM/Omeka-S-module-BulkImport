<?php declare(strict_types=1);

namespace BulkImport\Stdlib;

use Laminas\Log\Logger;

/**
 * Error key/message store with severity level.
 *
 * Supported severities are error, warning, notice and info.
 * Other levels are supported via message levels.
 *
 * @see \Omeka\Stdlib\ErrorStore
 */
class MessageStore extends \Omeka\Stdlib\ErrorStore
{
    /**
     * @var array
     */
    protected $messages = [];

    /**
     * Add a message.
     *
     * @param string $key
     * @param string|\Omeka\Stdlib\Message|array $message A message string, a
     * Message object, or a nested ErrorStore array structure.
     * @param int $severity
     */
    public function addMessage($key, $message, $severity = Logger::ERR): self
    {
        $this->messages[$severity][$key][] = $message;
        return $this;
    }

    /**
     * Add an error.
     */
    public function addError($key, $message): self
    {
        $this->messages[Logger::ERR][$key][] = $message;
        return $this;
    }

    /**
     * Add a warning.
     */
    public function addWarning($key, $message): self
    {
        $this->messages[Logger::WARN][$key][] = $message;
        return $this;
    }

    /**
     * Add a notice.
     */
    public function addNotice($key, $message): self
    {
        $this->messages[Logger::NOTICE][$key][] = $message;
        return $this;
    }

    /**
     * Add an info.
     */
    public function addInfo($key, $message): self
    {
        $this->messages[Logger::INFO][$key][] = $message;
        return $this;
    }

    /**
     * Merge errors of an ErrorStore onto this one.
     *
     * Like parent, but fixed.
     */
    public function mergeErrors(\Omeka\Stdlib\ErrorStore $errorStore, $key = null)
    {
        if ($key === null) {
            foreach ($errorStore->getErrors() as $origKey => $messages) {
                if (is_array($messages)) {
                    foreach ($messages as $message) {
                        $this->addError($origKey, $message);
                    }
                } else {
                    $this->addError($origKey, $message);
                }
            }
        } elseif ($errorStore->hasErrors()) {
            $this->addError($key, $errorStore->getErrors());
        }
        return $this;
    }

    /**
     * Add errors derived from Zend validator messages.
     *
     * @param array $errors
     * @param null|string $customKey
     */
    public function addValidatorMessages($key, array $messages, $severity = Logger::ERR): self
    {
        foreach ($messages as $message) {
            $this->addMessage($key, $message, $severity);
        }
        return $this;
    }

    /**
     * The message store is recursive, so merge all sublevels into a flat array.
     */
    public function flatMessages($severity = null): self
    {
        if (is_null($severity)) {
            $severities = array_keys($this->messages);
            foreach ($severities as $severity) {
                $this->flatMessages($severity);
            }
        } elseif (empty($this->messages[$severity])) {
            unset($this->messages[$severity]);
        } else {
            // The keys of the first level should be kept, but not the sub-ones.
            $flatArray = [];
            foreach ($this->messages[$severity] as $key => $message) {
                if (is_array($message)) {
                    array_walk_recursive($message, function ($data) use (&$flatArray, $key): void {
                        $flatArray[$key][] = $data;
                    });
                } else {
                    $flatArray[$key][] = $message;
                }
            }
            $this->messages[$severity] = $flatArray;
        }
        return $this;
    }

    /**
     * Get messages (at least double nested array without severity).
     */
    public function getMessages($severity = null): array
    {
        return is_null($severity)
            ? $this->messages
            : $this->messages[$severity] ?? [];
    }

    /**
     * Clear messages.
     */
    public function clearMessages($severity = null): self
    {
        if (is_null($severity)) {
            $this->messages = [];
        } else {
            unset($this->messages[$severity]);
        }
        return $this;
    }

    /**
     * Check whether the store contains messages.
     */
    public function hasMessages($severity = null): bool
    {
        return is_null($severity)
            ? (bool) count($this->messages)
            : !empty($this->messages[$severity]);
    }

    /**
     * Get errors.
     */
    public function getErrors(): array
    {
        return $this->messages[Logger::ERR] ?? [];
    }

    /**
     * Clear errors.
     */
    public function clearErrors(): self
    {
        $this->messages[Logger::ERR] = [];
        return $this;
    }

    /**
     * Check whether the store contains errors.
     */
    public function hasErrors(): bool
    {
        return !empty($this->messages[Logger::ERR]);
    }

    /**
     * Get warnings.
     */
    public function getWarnings(): array
    {
        return $this->messages[Logger::WARN] ?? [];
    }

    /**
     * Clear warnings.
     */
    public function clearWarnings(): self
    {
        $this->messages[Logger::WARN] = [];
        return $this;
    }

    /**
     * Check whether the store contains warnings.
     */
    public function hasWarnings(): bool
    {
        return !empty($this->messages[Logger::WARN]);
    }

    /**
     * Get notices.
     */
    public function getNotices(): array
    {
        return $this->messages[Logger::NOTICE] ?? [];
    }

    /**
     * Clear notices.
     */
    public function clearNotices(): self
    {
        $this->messages[Logger::NOTICE] = [];
        return $this;
    }

    /**
     * Check whether the error store contains notices.
     */
    public function hasNotices(): bool
    {
        return !empty($this->messages[Logger::NOTICE]);
    }

    /**
     * Get infos.
     */
    public function getInfos(): array
    {
        return $this->messages[Logger::INFO] ?? [];
    }

    /**
     * Clear infos.
     */
    public function clearInfos(): self
    {
        $this->messages[Logger::INFO] = [];
        return $this;
    }

    /**
     * Check whether the error store contains infos.
     */
    public function hasInfos(): bool
    {
        return !empty($this->messages[Logger::INFO]);
    }
}
