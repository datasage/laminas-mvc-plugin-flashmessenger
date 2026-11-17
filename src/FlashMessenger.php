<?php

declare(strict_types=1);

namespace Laminas\Mvc\Plugin\FlashMessenger;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Session\Container;
use Laminas\Session\ManagerInterface as Manager;
use Laminas\Stdlib\SplQueue;
use Override;

use function assert;
use function count;

/**
 * Flash Messenger - implement session-based messages
 *
 * @template-implements IteratorAggregate<array-key, string>
 * @psalm-type MessageList = SplQueue<array-key, string>
 */
class FlashMessenger extends AbstractPlugin implements IteratorAggregate, Countable
{
    /**
     * Default messages namespace
     */
    public const NAMESPACE_DEFAULT = 'default';

    /**
     * Success messages namespace
     */
    public const NAMESPACE_SUCCESS = 'success';

    /**
     * Warning messages namespace
     */
    public const NAMESPACE_WARNING = 'warning';

    /**
     * Error messages namespace
     */
    public const NAMESPACE_ERROR = 'error';

    /**
     * Info messages namespace
     */
    public const NAMESPACE_INFO = 'info';

    protected ?Container $container = null;

    /**
     * Messages from previous request
     *
     * @var array<string, MessageList>
     */
    protected array $messages = [];

    protected ?Manager $session = null;

    /**
     * Whether a message has been added during this request
     */
    protected bool $messageAdded = false;

    /**
     * Instance namespace, default is 'default'
     */
    protected string $namespace = self::NAMESPACE_DEFAULT;

    /**
     * Set the session manager
     *
     * @return FlashMessenger
     */
    public function setSessionManager(Manager $manager): static
    {
        $this->session = $manager;
        return $this;
    }

    /**
     * Retrieve the session manager
     *
     * If none composed, lazy-loads a SessionManager instance
     */
    public function getSessionManager(): Manager
    {
        if (! $this->session instanceof Manager) {
            $this->setSessionManager(Container::getDefaultManager());
        }

        assert($this->session instanceof Manager);

        return $this->session;
    }

    /**
     * Get session container for flash messages
     */
    public function getContainer(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $manager         = $this->getSessionManager();
        $this->container = new Container('FlashMessenger', $manager);

        return $this->container;
    }

    /**
     * Change the namespace messages are added to
     *
     * Useful for per action controller messaging between requests
     *
     * @return FlashMessenger Provides a fluent interface
     */
    public function setNamespace(string $namespace = 'default'): static
    {
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Get the message namespace
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Add a message
     *
     * @param  string         $message
     * @return FlashMessenger Provides a fluent interface
     */
    public function addMessage($message, ?string $namespace = null, int $hops = 1): static
    {
        $container = $this->getContainer();

        if (null === $namespace) {
            $namespace = $this->getNamespace();
        }

        if (! $this->messageAdded) {
            $this->getMessagesFromContainer();
            $container->setExpirationHops($hops, null);
        }

        if (
            ! isset($container->{$namespace})
            || ! $container->{$namespace} instanceof SplQueue
        ) {
            $container->{$namespace} = new SplQueue();
        }

        $container->{$namespace}->push($message);

        $this->messageAdded = true;

        return $this;
    }

    /**
     * Add a message with "info" type
     *
     * @param  string         $message
     * @return FlashMessenger
     */
    public function addInfoMessage($message): static
    {
        $this->addMessage($message, self::NAMESPACE_INFO);
        return $this;
    }

    /**
     * Add a message with "success" type
     *
     * @param  string         $message
     * @return FlashMessenger
     */
    public function addSuccessMessage($message): static
    {
        $this->addMessage($message, self::NAMESPACE_SUCCESS);
        return $this;
    }

    /**
     * Add a message with "warning" type
     *
     * @param string        $message
     * @return FlashMessenger
     */
    public function addWarningMessage($message): static
    {
        $this->addMessage($message, self::NAMESPACE_WARNING);
        return $this;
    }

    /**
     * Add a message with "error" type
     *
     * @param  string         $message
     * @return FlashMessenger
     */
    public function addErrorMessage($message): static
    {
        $this->addMessage($message, self::NAMESPACE_ERROR);
        return $this;
    }

    /**
     * Whether a specific namespace has messages
     */
    public function hasMessages(?string $namespace = null): bool
    {
        if (null === $namespace) {
            $namespace = $this->getNamespace();
        }

        $this->getMessagesFromContainer();

        return isset($this->messages[$namespace]);
    }

    /**
     * Whether "info" namespace has messages
     */
    public function hasInfoMessages(): bool
    {
        return $this->hasMessages(self::NAMESPACE_INFO);
    }

    /**
     * Whether "success" namespace has messages
     */
    public function hasSuccessMessages(): bool
    {
        return $this->hasMessages(self::NAMESPACE_SUCCESS);
    }

    /**
     * Whether "warning" namespace has messages
     */
    public function hasWarningMessages(): bool
    {
        return $this->hasMessages(self::NAMESPACE_WARNING);
    }

    /**
     * Whether "error" namespace has messages
     */
    public function hasErrorMessages(): bool
    {
        return $this->hasMessages(self::NAMESPACE_ERROR);
    }

    /**
     * Get messages from a specific namespace
     *
     * @return array<array-key, string>
     */
    public function getMessages(?string $namespace = null): array
    {
        if (null === $namespace) {
            $namespace = $this->getNamespace();
        }

        if ($this->hasMessages($namespace)) {
            return $this->messages[$namespace]->toArray();
        }

        return [];
    }

    /**
     * Get messages from "info" namespace
     */
    public function getInfoMessages(): array
    {
        return $this->getMessages(self::NAMESPACE_INFO);
    }

    /**
     * Get messages from "success" namespace
     *
     * @return array<array-key, string>
     */
    public function getSuccessMessages(): array
    {
        return $this->getMessages(self::NAMESPACE_SUCCESS);
    }

    /**
     * Get messages from "warning" namespace
     *
     * @return array<array-key, string>
     */
    public function getWarningMessages(): array
    {
        return $this->getMessages(self::NAMESPACE_WARNING);
    }

    /**
     * Get messages from "error" namespace
     *
     * @return array<array-key, string>
     */
    public function getErrorMessages(): array
    {
        return $this->getMessages(self::NAMESPACE_ERROR);
    }

    /**
     * Clear all messages from the previous request & current namespace
     *
     * @return bool True if messages were cleared, false if none existed
     */
    public function clearMessages(?string $namespace = null): bool
    {
        if (null === $namespace) {
            $namespace = $this->getNamespace();
        }

        if ($this->hasMessages($namespace)) {
            unset($this->messages[$namespace]);
            return true;
        }

        return false;
    }

    /**
     * Clear all messages from specific namespace
     *
     * @return bool True if messages were cleared, false if none existed
     */
    public function clearMessagesFromNamespace(string $namespaceToClear): bool
    {
        return $this->clearMessages($namespaceToClear);
    }

    /**
     * Clear all messages from the container
     *
     * @return bool True if messages were cleared, false if none existed
     */
    public function clearMessagesFromContainer(): bool
    {
        $this->getMessagesFromContainer();
        if (empty($this->messages)) {
            return false;
        }

        unset($this->messages);
        $this->messages = [];

        return true;
    }

    /**
     * Check to see if messages have been added to the current
     * namespace within this request
     */
    public function hasCurrentMessages(?string $namespace = null): bool
    {
        $container = $this->getContainer();
        if (null === $namespace) {
            $namespace = $this->getNamespace();
        }

        return isset($container->{$namespace});
    }

    /**
     * Check to see if messages have been added to "info"
     * namespace within this request
     */
    public function hasCurrentInfoMessages(): bool
    {
        return $this->hasCurrentMessages(self::NAMESPACE_INFO);
    }

    /**
     * Check to see if messages have been added to "success"
     * namespace within this request
     */
    public function hasCurrentSuccessMessages(): bool
    {
        return $this->hasCurrentMessages(self::NAMESPACE_SUCCESS);
    }

    /**
     * Check to see if messages have been added to "warning"
     * namespace within this request
     */
    public function hasCurrentWarningMessages(): bool
    {
        return $this->hasCurrentMessages(self::NAMESPACE_WARNING);
    }

    /**
     * Check to see if messages have been added to "error"
     * namespace within this request
     */
    public function hasCurrentErrorMessages(): bool
    {
        return $this->hasCurrentMessages(self::NAMESPACE_ERROR);
    }

    /**
     * Get messages that have been added to the current
     * namespace within this request
     *
     * @return array<array-key, string>
     */
    public function getCurrentMessages(?string $namespace = null): array
    {
        if (null === $namespace) {
            $namespace = $this->getNamespace();
        }

        if ($this->hasCurrentMessages($namespace)) {
            $container = $this->getContainer();
            /** @psalm-var MessageList $queue */
            $queue = $container->{$namespace};

            return $queue->toArray();
        }

        return [];
    }

    /**
     * Get messages that have been added to the "info"
     * namespace within this request
     *
     * @return array<array-key, string>
     */
    public function getCurrentInfoMessages(): array
    {
        return $this->getCurrentMessages(self::NAMESPACE_INFO);
    }

    /**
     * Get messages that have been added to the "success"
     * namespace within this request
     *
     * @return array<array-key, string>
     */
    public function getCurrentSuccessMessages(): array
    {
        return $this->getCurrentMessages(self::NAMESPACE_SUCCESS);
    }

    /**
     * Get messages that have been added to the "warning"
     * namespace within this request
     *
     * @return array<array-key, string>
     */
    public function getCurrentWarningMessages(): array
    {
        return $this->getCurrentMessages(self::NAMESPACE_WARNING);
    }

    /**
     * Get messages that have been added to the "error"
     * namespace within this request
     *
     * @return array<array-key, string>
     */
    public function getCurrentErrorMessages(): array
    {
        return $this->getCurrentMessages(self::NAMESPACE_ERROR);
    }

    /**
     * Get messages that have been added to the current
     * namespace in specific namespace
     *
     * @return array<array-key, string>
     */
    public function getCurrentMessagesFromNamespace(string $namespaceToGet): array
    {
        return $this->getCurrentMessages($namespaceToGet);
    }

    /**
     * Clear messages from the current request and current namespace
     *
     * @return bool True if current messages were cleared, false if none existed.
     */
    public function clearCurrentMessages(?string $namespace = null): bool
    {
        if (null === $namespace) {
            $namespace = $this->getNamespace();
        }

        if ($this->hasCurrentMessages($namespace)) {
            $container = $this->getContainer();
            unset($container->{$namespace});
            return true;
        }

        return false;
    }

    /**
     * Clear messages from the current namespace
     *
     * @return bool True if current messages were cleared from the given namespace, false if none existed.
     */
    public function clearCurrentMessagesFromNamespace(string $namespaceToClear): bool
    {
        return $this->clearCurrentMessages($namespaceToClear);
    }

    /**
     * Clear messages from the container
     *
     * @return bool True if current messages were cleared from the container, false if none existed.
     */
    public function clearCurrentMessagesFromContainer(): bool
    {
        $container = $this->getContainer();

        $namespaces = [];
        foreach ($container as $namespace => $messages) {
            $namespaces[] = $namespace;
        }

        if (empty($namespaces)) {
            return false;
        }

        foreach ($namespaces as $namespace) {
            unset($container->{$namespace});
        }

        return true;
    }

    /**
     * Complete the IteratorAggregate interface, for iterating
     *
     * @return ArrayIterator<array-key, string>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->getMessages());
    }

    /**
     * Complete the countable interface
     */
    #[Override]
    public function count(): int
    {
        return count($this->getMessages());
    }

    /**
     * Get messages from a specific namespace
     *
     * @return array<array-key, string>
     */
    public function getMessagesFromNamespace(string $namespaceToGet): array
    {
        return $this->getMessages($namespaceToGet);
    }

    /**
     * Pull messages from the session container
     *
     * Iterates through the session container, removing messages into the local
     * scope.
     */
    protected function getMessagesFromContainer(): void
    {
        if (! empty($this->messages) || $this->messageAdded) {
            return;
        }

        $container = $this->getContainer();

        $namespaces = [];
        foreach ($container as $namespace => $messages) {
            $this->messages[$namespace] = $messages;
            $namespaces[]               = $namespace;
        }

        foreach ($namespaces as $namespace) {
            unset($container->{$namespace});
        }
    }
}
