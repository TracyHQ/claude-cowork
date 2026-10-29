<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Tracy\Plugin\System\ClaudeCoworkApi\Extension\ClaudeCoworkApi;
use Tracy\Plugin\System\ClaudeCoworkApi\Extension\PhpFloorDoor;

\defined('_JEXEC') or die;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                // On a PHP older than the engine needs, `ClaudeCoworkApi` does not parse: a plugin that
                // only answers the door with `php_too_old` stands in, and every page is left alone.
                $floor = JPATH_ADMINISTRATOR . '/components/com_claudecowork/legacy/PhpFloor.php';
                if (\PHP_VERSION_ID < 70400 && is_file($floor)) {
                    $plugin = new PhpFloorDoor(
                        $container->get(DispatcherInterface::class),
                        (array) PluginHelper::getPlugin('system', 'claudecoworkapi')
                    );
                    $plugin->setApplication(Factory::getApplication());

                    return $plugin;
                }
                $plugin = new ClaudeCoworkApi(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'claudecoworkapi')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
