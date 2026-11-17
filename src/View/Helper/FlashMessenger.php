<?php

declare(strict_types=1);

namespace Laminas\Mvc\Plugin\FlashMessenger\View\Helper;

use Laminas\Mvc\Plugin\FlashMessenger\FlashMessenger as PluginFlashMessenger;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Helper\EscapeHtml;
use Laminas\View\Helper\TranslatorAwareTrait;

use function array_walk_recursive;
use function assert;
use function implode;
use function method_exists;
use function sprintf;

/**
 * Helper to proxy the plugin flash messenger
 * Duck-types against Laminas\I18n\Translator\TranslatorAwareInterface.
 *
 * @method PluginFlashMessenger addMessage(string $string)
 * @method PluginFlashMessenger addInfoMessage(string $string)
 * @method PluginFlashMessenger addSuccessMessage(string $string)
 * @method PluginFlashMessenger addErrorMessage(string $string)
 * @method PluginFlashMessenger addWarningMessage(string $string)
 * @method bool hasMessages(?string $namespace = null)
 * @method bool hasInfoMessages()
 * @method bool hasSuccessMessages()
 * @method bool hasErrorMessages()
 * @method bool hasWarningMessages()
 * @method bool hasCurrentMessages(?string $namespace = null)
 * @method bool hasCurrentInfoMessages()
 * @method bool hasCurrentSuccessMessages()
 * @method bool hasCurrentErrorMessages()
 * @method bool hasCurrentWarningMessages()
 */
class FlashMessenger extends AbstractHelper
{
    use TranslatorAwareTrait;

    /**
     * Default attributes for the open format tag
     *
     * @var array<string, string>
     */
    protected array $classMessages = [
        'info'    => PluginFlashMessenger::NAMESPACE_INFO,
        'error'   => PluginFlashMessenger::NAMESPACE_ERROR,
        'success' => PluginFlashMessenger::NAMESPACE_SUCCESS,
        'default' => PluginFlashMessenger::NAMESPACE_DEFAULT,
        'warning' => PluginFlashMessenger::NAMESPACE_WARNING,
    ];

    /**
     * Templates for the open/close/separators for message tags
     */
    protected string $messageCloseString     = '</li></ul>';
    protected string $messageOpenFormat      = '<ul%s><li>';
    protected string $messageSeparatorString = '</li><li>';

    /**
     * Flag whether to escape messages
     */
    protected bool $autoEscape = true;

    /**
     * Html escape helper
     */
    protected ?EscapeHtml $escapeHtmlHelper = null;

    /**
     * Flash messenger plugin
     */
    protected ?PluginFlashMessenger $pluginFlashMessenger = null;

    /**
     * All namespaces of FlashMessenger
     *
     * Keys of array are the namespace names
     *
     * @var array<string, FlashMessengerNamespace> $namespaces
     */
    private array $namespaces = [];

    /**
     * @param array<string, FlashMessengerNamespace> $namespaces
     */
    public function __construct(array $namespaces = [])
    {
        foreach ($namespaces as $namespace) {
            $this->namespaces[$namespace->getName()] = $namespace;
        }
    }

    /**
     * Returns the flash messenger plugin controller
     *
     * @return FlashMessenger|PluginFlashMessenger|array<array-key, string>
     */
    public function __invoke(?string $namespace = null): static|array
    {
        if (null === $namespace) {
            return $this;
        }
        $flashMessenger = $this->getPluginFlashMessenger();

        return $flashMessenger->getMessagesFromNamespace($namespace);
    }

    /**
     * Proxy the flash messenger plugin controller
     *
     * @param  string $method
     * @param  array  $argv
     * @return mixed
     */
    public function __call($method, $argv)
    {
        return $this->getPluginFlashMessenger()->$method(...$argv);
    }

    /**
     * Render Messages
     *
     * @param  array<array-key, string> $classes
     */
    public function render(string $namespace = 'default', array $classes = [], ?bool $autoEscape = null): string
    {
        $flashMessenger = $this->getPluginFlashMessenger();
        $messages       = $flashMessenger->getMessagesFromNamespace($namespace);
        return $this->renderMessages($namespace, $messages, $classes, $autoEscape);
    }

    /**
     * Render Current Messages
     */
    public function renderCurrent(string $namespace = 'default', array $classes = [], ?bool $autoEscape = null): string
    {
        $flashMessenger = $this->getPluginFlashMessenger();
        $messages       = $flashMessenger->getCurrentMessagesFromNamespace($namespace);
        return $this->renderMessages($namespace, $messages, $classes, $autoEscape);
    }

    /**
     * Render Messages
     *
     * @param array<array-key, string> $messages
     * @param array<array-key, string> $classes
     */
    protected function renderMessages(
        string $namespace = 'default',
        array $messages = [],
        array $classes = [],
        ?bool $autoEscape = null
    ): string {
        if (empty($messages)) {
            return '';
        }

        // Prepare classes for opening tag
        if (empty($classes)) {
            $classes = [$this->getClasses($namespace)];
        }

        $autoEscape ??= $this->autoEscape;

        // Flatten message array
        $escapeHtml           = $this->getEscapeHtmlHelper();
        $messagesToPrint      = [];
        $translator           = $this->getTranslator();
        $translatorTextDomain = $this->getTranslatorTextDomain();
        array_walk_recursive(
            $messages,
            function ($item) use (&$messagesToPrint, $escapeHtml, $autoEscape, $translator, $translatorTextDomain) {
                if ($translator !== null) {
                    $item = $translator->translate(
                        $item,
                        $translatorTextDomain
                    );
                }

                if ($autoEscape) {
                    $messagesToPrint[] = $escapeHtml($item);
                    return;
                }

                $messagesToPrint[] = $item;
            }
        );

        if (empty($messagesToPrint)) {
            return '';
        }

        // Generate markup
        $markup  = sprintf($this->getMessageOpenFormat($namespace), ' class="' . implode(' ', $classes) . '"');
        $markup .= implode(
            sprintf($this->getMessageSeparatorString($namespace), ' class="' . implode(' ', $classes) . '"'),
            $messagesToPrint
        );
        $markup .= $this->getMessageCloseString($namespace);
        return $markup;
    }

    /**
     * Set whether or not auto escaping should be used
     *
     * @param  bool $autoEscape
     * @return self
     */
    public function setAutoEscape($autoEscape = true): static
    {
        $this->autoEscape = (bool) $autoEscape;
        return $this;
    }

    /**
     * Return whether auto escaping is enabled or disabled
     */
    public function getAutoEscape(): bool
    {
        return $this->autoEscape;
    }

    /**
     * Set the string used to close message representation
     *
     * @return FlashMessenger
     */
    public function setMessageCloseString(string $messageCloseString, ?string $namespaceName = null): static
    {
        $namespaceName = $namespaceName ?? $this->getDefaultNamespaceName();
        $namespace     = $this->getNamespace($namespaceName);
        if ($namespace === null) {
            $this->messageCloseString = $messageCloseString;
        } else {
            $namespace->setMessageCloseString($messageCloseString);
        }

        return $this;
    }

    /**
     * Get the string used to close message representation
     */
    public function getMessageCloseString(?string $namespaceName = null): string
    {
        $namespaceName = $namespaceName ?? $this->getDefaultNamespaceName();
        $namespace     = $this->getNamespace($namespaceName);
        if ($namespace === null) {
            return $this->messageCloseString;
        }

        return $namespace->getMessageCloseString();
    }

    /**
     * Set the formatted string used to open message representation
     *
     * @return FlashMessenger
     */
    public function setMessageOpenFormat(string $messageOpenFormat, ?string $namespaceName = null): static
    {
        $namespaceName = $namespaceName ?? $this->getDefaultNamespaceName();
        $namespace     = $this->getNamespace($namespaceName);
        if ($namespace === null) {
            $this->messageOpenFormat = $messageOpenFormat;
        } else {
            $namespace->setMessageOpenFormat($messageOpenFormat);
        }
        return $this;
    }

    /**
     * Get the formatted string used to open message representation
     */
    public function getMessageOpenFormat(?string $namespaceName = null): string
    {
        $namespaceName = $namespaceName ?? $this->getDefaultNamespaceName();
        $namespace     = $this->getNamespace($namespaceName);
        if ($namespace === null) {
            return $this->messageOpenFormat;
        }

        return $namespace->getMessageOpenFormat();
    }

    /**
     * Set the string used to separate messages
     *
     * @return FlashMessenger
     */
    public function setMessageSeparatorString(string $messageSeparatorString, ?string $namespaceName = null): static
    {
        $namespaceName = $namespaceName ?? $this->getDefaultNamespaceName();
        $namespace     = $this->getNamespace($namespaceName);
        if ($namespace === null) {
            $this->messageSeparatorString = $messageSeparatorString;
        } else {
            $namespace->setMessageSeparatorString($messageSeparatorString);
        }
        return $this;
    }

    /**
     * Get the string used to separate messages
     */
    public function getMessageSeparatorString(?string $namespaceName = null): string
    {
        $namespaceName = $namespaceName ?? $this->getDefaultNamespaceName();
        $namespace     = $this->getNamespace($namespaceName);
        if ($namespace === null) {
            return $this->messageSeparatorString;
        }

        return $namespace->getMessageSeparatorString();
    }

    /**
     * Set the flash messenger plugin
     *
     * @return FlashMessenger
     */
    public function setPluginFlashMessenger(PluginFlashMessenger $pluginFlashMessenger): static
    {
        $this->pluginFlashMessenger = $pluginFlashMessenger;
        return $this;
    }

    /**
     * Get the flash messenger plugin
     */
    public function getPluginFlashMessenger(): PluginFlashMessenger
    {
        if (null === $this->pluginFlashMessenger) {
            $this->setPluginFlashMessenger(new PluginFlashMessenger());
        }
        assert($this->pluginFlashMessenger instanceof PluginFlashMessenger);

        return $this->pluginFlashMessenger;
    }

    /**
     * Retrieve the escapeHtml helper
     */
    protected function getEscapeHtmlHelper(): EscapeHtml
    {
        if ($this->escapeHtmlHelper !== null) {
            return $this->escapeHtmlHelper;
        }

        $view = $this->getView();

        if ($view && method_exists($view, 'plugin')) {
            $plugin = $view->plugin('escapehtml');
            assert($plugin instanceof EscapeHtml);
            $this->escapeHtmlHelper = $plugin;
        }

        if (! $this->escapeHtmlHelper instanceof EscapeHtml) {
            $this->escapeHtmlHelper = new EscapeHtml();
        }

        return $this->escapeHtmlHelper;
    }

    private function getNamespace(string $namespace): ?FlashMessengerNamespace
    {
        return $this->namespaces[$namespace] ?? null;
    }

    private function getClasses(string $namespaceName): string
    {
        $namespace = $this->getNamespace($namespaceName);
        if ($namespace === null) {
            return $this->classMessages[$namespaceName] ?? '';
        }

        return $namespace->getClasses();
    }

    /**
     * Returns default namespace name
     */
    private function getDefaultNamespaceName(): string
    {
        return PluginFlashMessenger::NAMESPACE_DEFAULT;
    }
}
