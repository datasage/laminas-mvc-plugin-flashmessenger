<?php

declare(strict_types=1);

namespace Laminas\Mvc\Plugin\FlashMessenger\View\Helper;

use Laminas\Mvc\Plugin\FlashMessenger\FlashMessenger as PluginFlashMessenger;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Override;
use Psr\Container\ContainerInterface;

use function is_array;

class FlashMessengerFactory implements FactoryInterface
{
    /**
     * Create service
     *
     * @param string $name
     * @param null|array $options
     */
    #[Override]
    public function __invoke(ContainerInterface $container, $name, ?array $options = null): FlashMessenger
    {
        $controllerPluginManager = $container->get('ControllerPluginManager');
        /** @var PluginFlashMessenger $flashMessenger */
        $flashMessenger = $controllerPluginManager->get('flashmessenger');

        $config = $container->get('config');
        if (isset($config['view_helper_config']['flashmessenger'])) {
            $configHelper = (array) $config['view_helper_config']['flashmessenger'];

            $isArrayOneDimensional = $this->isArrayOneDimensional($configHelper);

            if ($isArrayOneDimensional === true) {
                return $this->createHelperWithOldConfig($flashMessenger, $configHelper);
            } else {
                return $this->createHelperWithActualConfig($flashMessenger, $configHelper);
            }
        }

        return new FlashMessenger();
    }

    /**
     * @param array $configHelper {
     *      message_open_format?:string, message_close_string?:string, message_separator_string?:string
     * }
     */
    private function createHelperWithOldConfig(
        PluginFlashMessenger $flashMessenger,
        array $configHelper
    ): FlashMessenger {
        $helper = new FlashMessenger();
        $helper->setPluginFlashMessenger($flashMessenger);

        if (isset($configHelper['message_open_format'])) {
            $helper
                ->setMessageOpenFormat((string) $configHelper['message_open_format']);
        }
        if (isset($configHelper['message_separator_string'])) {
            $helper
                ->setMessageSeparatorString((string) $configHelper['message_separator_string']);
        }
        if (isset($configHelper['message_close_string'])) {
            $helper
                ->setMessageCloseString((string) $configHelper['message_close_string']);
        }

        return $helper;
    }

    private function createHelperWithActualConfig(
        PluginFlashMessenger $flashMessenger,
        array $configHelper
    ): FlashMessenger {
        $namespaces = [];
        /**
         * @var array<string, mixed> $arrProperties {
         * classes?:string, message_open_format?:string, message_close_string?:string, message_separator_string?:string
         * }
         */
        foreach ($configHelper as $configNamespace => $arrProperties) {
            $namespace = new FlashMessengerNamespace(
                (string) $configNamespace,
                isset($arrProperties['classes']) ? (string) $arrProperties['classes'] : ''
            );
            if (isset($arrProperties['message_open_format'])) {
                $namespace
                    ->setMessageOpenFormat((string) $arrProperties['message_open_format']);
            }
            if (isset($arrProperties['message_separator_string'])) {
                $namespace
                    ->setMessageSeparatorString((string) $arrProperties['message_separator_string']);
            }
            if (isset($arrProperties['message_close_string'])) {
                $namespace
                    ->setMessageCloseString((string) $arrProperties['message_close_string']);
            }
            $namespaces[$namespace->getName()] = $namespace;
        }

        $helper = new FlashMessenger($namespaces);
        $helper->setPluginFlashMessenger($flashMessenger);
        return $helper;
    }

    private function isArrayOneDimensional(array $array): bool
    {
        /** @var mixed $property */
        foreach ($array as $property) {
            if (is_array($property)) {
                return false;
            }
        }

        return true;
    }
}
