<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Tracy\Plugin\System\ClaudeCoworkUpdate\Extension\ClaudeCoworkUpdate;

\defined('_JEXEC') or die;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new ClaudeCoworkUpdate(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'claudecoworkupdate')
                );
                $plugin->setApplication(Factory::getApplication());
                // It reads #__extensions to know what is installed; without this getDatabase() throws.
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
