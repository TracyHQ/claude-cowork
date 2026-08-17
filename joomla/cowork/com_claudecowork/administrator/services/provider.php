<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Tracy\Component\ClaudeCowork\Administrator\Extension\ClaudeCoworkComponent;

/**
 * The component has no admin screens yet — this exists so Joomla can install it, show it in
 * the extension list, and open its Options dialog, which is where the token is set.
 */
return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\Tracy\\Component\\ClaudeCowork'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Tracy\\Component\\ClaudeCowork'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new ClaudeCoworkComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            }
        );
    }
};
