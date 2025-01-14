<?php

/*
 * This file is part of the Ivory Google Map bundle package.
 *
 * (c) Eric GELOEN <geloen.eric@gmail.com>
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code.
 */

namespace Ivory\GoogleMapBundle\DependencyInjection;

use Exception;
use Ivory\GoogleMap\Service\BusinessAccount;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

/**
 * @author GeLo <geloen.eric@gmail.com>
 */
class IvoryGoogleMapExtension extends ConfigurableExtension
{
    /**
     * {@inheritdoc}
     */
    protected function loadInternal(array $config, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        $resources = [
            'form',
            'helper/collector',
            'helper/helper',
            'helper/renderer',
            'helper/subscriber',
            'helper/utility',
            'templating',
            'twig',
        ];

        foreach ($resources as $resource) {
            $loader->load($resource.'.xml');
        }

        $this->loadMapConfig($config['map'], $container);
        $this->loadStaticMapConfig($config['static_map'], $container);
        $this->loadServicesConfig($config, $container, $loader);
    }

    /**
     * @param mixed[] $config
     */
    private function loadMapConfig(array $config, ContainerBuilder $container)
    {
        $container
            ->getDefinition('ivory.google_map.helper.renderer.loader')
            ->addArgument($config['language']);

        if ($config['debug']) {
            $container
                ->getDefinition('ivory.google_map.helper.formatter')
                ->addArgument($config['debug']);
        }

        if (isset($config['api_key'])) {
            $container
                ->getDefinition('ivory.google_map.helper.renderer.loader')
                ->addArgument($config['api_key']);
        }
    }

    /**
     * @param mixed[] $config
     */
    private function loadStaticMapConfig(array $config, ContainerBuilder $container)
    {
        if (isset($config['api_key'])) {
            $container
                ->getDefinition('ivory.google_map.helper.subscriber.static.key')
                ->addArgument($config['api_key']);
        }

        if (isset($config['business_account'])) {
            $businessAccount = $config['business_account'];

            $container
                ->getDefinition('ivory.google_map.helper.map.static')
                ->addArgument($businessAccount['secret'] ?? null)
                ->addArgument($businessAccount['client_id'] ?? null)
                ->addArgument($businessAccount['channel'] ?? null);
        }
    }

    /**
     * @param mixed[] $config
     */
    private function loadServicesConfig(array $config, ContainerBuilder $container, LoaderInterface $loader)
    {
        $services = [
            'direction'          => true,
            'distance_matrix'    => true,
            'elevation'          => true,
            'geocoder'           => true,
            'place_autocomplete' => true,
            'place_detail'       => true,
            'place_photo'        => false,
            'place_search'       => true,
            'time_zone'          => true,
        ];

        $serializerLoaded = false;
        $loadSerializer = function() use ($container, $loader) {
            $loader->load('service/serializer.xml');

            if ($container->hasParameter('kernel.project_dir')) {
                $container
                    ->getDefinition('ivory.google_map.serializer.loader')
                    ->replaceArgument(0, '%kernel.project_dir%/vendor/ivory/google-map/src/Service/Serializer');
            }
        };

        foreach ($services as $service => $http) {
            if (!isset($config[$service])) {
                continue;
            }

            if ($http && !$serializerLoaded) {
                $loadSerializer();
                $serializerLoaded = true;
            }

            $this->loadServiceConfig($service, $config[$service], $container, $loader, $http);
        }
    }

    /**
     * @param string  $service
     * @param mixed[] $config
     * @param bool    $http
     *
     * @throws Exception
     */
    private function loadServiceConfig(
        string $service,
        array $config,
        ContainerBuilder $container,
        LoaderInterface $loader,
        $http = true
    ) {
        $loader->load('service/'.$service.'.xml');
        $definition = $container->getDefinition($serviceName = 'ivory.google_map.'.$service);

        if ($http) {
            $definition
                ->addArgument(new Reference($config['client']))
                ->addArgument(new Reference($config['message_factory']))
                ->addArgument(new Reference('ivory.serializer'));
        }

        if ($http && isset($config['format'])) {
            $definition->addMethodCall('setFormat', [$config['format']]);
        }

        if (isset($config['api_key'])) {
            $definition->addMethodCall('setKey', [$config['api_key']]);
        }

        if (isset($config['business_account'])) {
            $businessAccountConfig = $config['business_account'];

            $container->setDefinition(
                $businessAccountName = $serviceName.'.business_account',
                new Definition(BusinessAccount::class, [
                    $businessAccountConfig['client_id'],
                    $businessAccountConfig['secret'],
                    $businessAccountConfig['channel'] ?? null,
                ])
            );

            $definition->addMethodCall('setBusinessAccount', [new Reference($businessAccountName)]);
        }
    }
}
