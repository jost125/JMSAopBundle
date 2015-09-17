<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\AopBundle\DependencyInjection;

use JMS\AopBundle\Exception\RuntimeException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * JMSAopExtension.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class JMSAopExtension extends Extension
{
    /**
     * {@inheritDoc}
     * @param array<array<string,string>> $configs
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->mergeConfigs($configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        $cacheDir = $container->getParameterBag()->resolveValue($config['cache_dir']);
        $useCompilationCache = $container->getParameterBag()->resolveValue($config['use_compilation_cache']);
        if (!is_dir($cacheDir)) {
            if (false === @mkdir($cacheDir, 0777, true)) {
                throw new RuntimeException(sprintf('Could not create cache directory "%s".', $cacheDir));
            }
        }
        $container->setParameter('jms_aop.cache_dir', $cacheDir);
        $container->setParameter('jms_aop.use_compilation_cache', $useCompilationCache);
        $container->setParameter(
           'jms_aop.compilation_cache_provider_service',
           $container->getParameterBag()->resolveValue($config['compilation_cache_provider_service'])
        );
    }


    private function mergeConfigs(array $configs) {
        $processor = new Processor();
        $configuration = new Configuration();

        return $processor->process($configuration->getConfigTreeBuilder()->buildTree(), $configs);
    }
}
