<?php

/*
 * This file is part of the BeSimpleSoapBundle.
 *
 * (c) Christian Kerl <christian-kerl@web.de>
 * (c) Francis Besset <francis.besset@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace BeSimple\SoapBundle\DependencyInjection;

use BeSimple\SoapCommon\Cache;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * BeSimpleSoapExtension.
 *
 * @author Christian Kerl <christian-kerl@web.de>
 * @author Francis Besset <francis.besset@gmail.com>
 */
class BeSimpleSoapExtension extends Extension
{
    // maps config options to service suffix
    private $bindingConfigToServiceSuffixMap = array(
        'rpc-literal'      => 'rpcliteral',
        'document-wrapped' => 'documentwrapped',
    );

    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        $loader->load('loaders.xml');
        $loader->load('converters.xml');
        $loader->load('webservice.xml');

        $processor     = new Processor();
        $configuration = new Configuration();

        $config = $processor->process($configuration->getConfigTree(), $configs);

        $this->registerCacheConfiguration($config['cache'], $container, $loader);

        if (!empty($config['clients'])) {
            $this->registerClientConfiguration($config['clients'], $container, $loader);
        }

        $container->setParameter('besimple.soap.definition.dumper.options.stylesheet', $config['wsdl_dumper']['stylesheet']);

        foreach($config['services'] as $name => $serviceConfig) {
            $serviceConfig['name'] = $name;
            $this->createWebServiceContext($serviceConfig, $container);
        }
    }

    private function registerCacheConfiguration(array $config, ContainerBuilder $container, XmlFileLoader $loader)
    {
        $loader->load('soap.xml');

        $config['type'] = $this->getCacheType($config['type']);

        foreach (array('type', 'lifetime', 'limit') as $key) {
            $container->setParameter('besimple.soap.cache.'.$key, $config[$key]);
        }
    }

    private function registerClientConfiguration(array $config, ContainerBuilder $container, XmlFileLoader $loader)
    {
        $loader->load('client.xml');

        foreach ($config as $client => $options) {
            $definition = new DefinitionDecorator('besimple.soap.client.builder');
            $context    = $container->setDefinition(sprintf('besimple.soap.client.builder.%s', $client), $definition);

            $definition->replaceArgument(0, $options['wsdl']);

            $defOptions = $container
                    ->getDefinition('besimple.soap.client.builder')
                    ->getArgument(1);

            foreach (array('cache_type', 'user_agent','timeout') as $key) {
                if (isset($options[$key])) {
                    $defOptions[$key] = $options[$key];
                }
            }

            if (isset($defOptions['cache_type'])) {
                $defOptions['cache_type'] = $this->getCacheType($defOptions['cache_type']);
            }

            $definition->replaceArgument(1, $defOptions);

            if (!empty($options['classmap'])) {
                $classmap = $this->createClientClassmap($client, $options['classmap'], $container);
                $definition->replaceArgument(2, new Reference($classmap));
            } else {
                $definition->replaceArgument(2, null);
            }

            $this->createClient($client, $container);
        }
    }

    private function createClientClassmap($client, array $classmap, ContainerBuilder $container)
    {
        $definition = new DefinitionDecorator('besimple.soap.classmap');
        $context    = $container->setDefinition(sprintf('besimple.soap.classmap.%s', $client), $definition);

        $definition->setMethodCalls(array(
            array('set', array($classmap)),
        ));

        return sprintf('besimple.soap.classmap.%s', $client);
    }

    private function createClient($client, ContainerBuilder $container)
    {
        $definition = new DefinitionDecorator('besimple.soap.client');
        $context    = $container->setDefinition(sprintf('besimple.soap.client.%s', $client), $definition);

        $definition->setFactoryService(sprintf('besimple.soap.client.builder.%s', $client));
    }

    private function createWebServiceContext(array $config, ContainerBuilder $container)
    {
        $bindingSuffix = $this->bindingConfigToServiceSuffixMap[$config['binding']];
        unset($config['binding']);

        $contextId  = 'besimple.soap.context.'.$config['name'];
        $definition = new DefinitionDecorator('besimple.soap.context.'.$bindingSuffix);
        $context    = $container->setDefinition($contextId, $definition);

        if (isset($config['cache_type'])) {
            $config['cache_type'] = $this->getCacheType($config['cache_type']);
        }

        $options = $container
            ->getDefinition('besimple.soap.context.'.$bindingSuffix)
            ->getArgument(5);

        $definition->replaceArgument(5, array_merge($options, $config));
    }

    private function getCacheType($type)
    {
        switch ($type) {
            case 'none':
                return Cache::TYPE_NONE;

            case 'disk':
                return Cache::TYPE_DISK;

            case 'memory':
                return Cache::TYPE_MEMORY;

            case 'disk_memory':
                return Cache::TYPE_DISK_MEMORY;
        }
    }
}
