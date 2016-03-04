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

namespace JMS\AopBundle\DependencyInjection\Compiler;

use CG\Core\ClassUtils;
use CG\Core\DefaultNamingStrategy;
use CG\Generator\RelativePath;
use JMS\AopBundle\DependencyInjection\CompilationCache;
use JMS\AopBundle\Exception\RuntimeException;
use ReflectionClass;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Reference;
use CG\Proxy\Enhancer;
use CG\Proxy\InterceptionGenerator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use JMS\AopBundle\Aop\PointcutInterface;
use CG\Core\ReflectionUtils;

/**
 * Matches pointcuts against service methods.
 *
 * This pass will collect the advices that match a certain method, and then
 * generate proxy classes where necessary.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class PointcutMatchingPass implements CompilerPassInterface
{
    private $pointcuts;
    private $pointcutsHash;
    private $cacheDir;
    private $container;
    private $useCompilationCache;
    /** @var CompilationCache */
    private $compilationCache;
    /**
     * @var DefaultNamingStrategy
     */
    private $namingStrategy;

    /**
     * @param array<PointcutInterface> $pointcuts
     */
    public function __construct(array $pointcuts = null)
    {
        $this->pointcuts = $pointcuts;
    }

    public function process(ContainerBuilder $container)
    {
        $this->container = $container;
        $this->cacheDir = $container->getParameter('jms_aop.cache_dir').'/proxies';
        $this->useCompilationCache = $container->getParameter('jms_aop.use_compilation_cache');
        if ($this->useCompilationCache) {
            $this->compilationCache = $container->get('jms_aop.compilation_cache');
            $this->compilationCache->load();
        }
        $pointcuts = $this->getPointcuts();
        $this->pointcutsHash = md5(serialize($pointcuts));
        $this->namingStrategy = new DefaultNamingStrategy('EnhancedProxy' . substr(md5($this->container->getParameter('jms_aop.cache_dir')), 0, 8));

        $interceptors = array();
        foreach ($container->getDefinitions() as $id => $definition) {
            $this->processDefinition($definition, $pointcuts, $interceptors);

            $this->processInlineDefinitions($pointcuts, $interceptors, $definition->getArguments());
            $this->processInlineDefinitions($pointcuts, $interceptors, $definition->getMethodCalls());
            $this->processInlineDefinitions($pointcuts, $interceptors, $definition->getProperties());
        }

        $container
            ->getDefinition('jms_aop.interceptor_loader')
            ->addArgument($interceptors)
        ;
    }

    /**
     * @param array<PointcutInterface> $pointcuts
     * @param array<string,string> $interceptors
     */
    private function processInlineDefinitions($pointcuts, &$interceptors, array $a)
    {
        foreach ($a as $k => $v) {
            if ($v instanceof Definition) {
                $this->processDefinition($v, $pointcuts, $interceptors);
            } elseif (is_array($v)) {
                $this->processInlineDefinitions($pointcuts, $interceptors, $v);
            }
        }
    }

    /**
     * @param array<PointcutInterface> $pointcuts
     * @param array<string,string> $interceptors
     */
    private function processDefinition(Definition $definition, $pointcuts, &$interceptors)
    {
        if ($definition->isSynthetic()) {
            return;
        }

        if ($definition->getFactoryService() || $definition->getFactoryClass()) {
            return;
        }

        if ($originalFilename = $definition->getFile()) {
            require_once $originalFilename;
        }

        if (!class_exists($definition->getClass())) {
            return;
        }

        $class = new \ReflectionClass($definition->getClass());
        $classFile = $class->getFileName();

        if (!$this->useCompilationCache || $this->shouldRecompile($pointcuts, $class)) {
            // check if class is matched
            $matchingPointcuts = [];
            foreach ($pointcuts as $interceptor => $pointcut) {
                if ($pointcut->matchesClass($class)) {
                    $matchingPointcuts[$interceptor] = $pointcut;
                }
            }

            $match = !empty($matchingPointcuts);
            $this->useCompilationCache && $this->compilationCache->savePointcutsMatch($this->pointcutsHash, $classFile, $match);
            if (!$match) {
                return;
            }

            $this->addResources($class, $this->container);

            if ($class->isFinal()) {
                return;
            }

            $classAdvices = [];
            $classNameMethods = [];
            foreach (ReflectionUtils::getOverrideableMethods($class) as $method) {
                if ('__construct' === $method->name) {
                    continue;
                }

                $advices = [];
                foreach ($matchingPointcuts as $interceptor => $pointcut) {
                    if ($pointcut->matchesMethod($method)) {
                        $advices[] = $interceptor;
                    }
                }

                if (empty($advices)) {
                    continue;
                }

                $classAdvices[$method->name] = $advices;
                $className = ClassUtils::getUserClass($method->getDeclaringClass()->getName());
                $interceptors[$className][$method->name] = $advices;
                $classNameMethods[$className][$method->name] = $advices;
            }

            $this->useCompilationCache && $this->compilationCache->saveClassAdvices(
               $this->pointcutsHash,
               $classFile,
               $classAdvices
            );

            $this->useCompilationCache && $this->compilationCache->saveClassNameMethods(
               $this->pointcutsHash,
               $classFile,
               $classNameMethods
            );

            if (empty($classAdvices)) {
                return;
            }

            $proxyFilename = $this->getProxyFilename($class);

            $generator = new InterceptionGenerator();
            $generator->setFilter(function (\ReflectionMethod $method) use ($classAdvices) {
                return isset($classAdvices[$method->name]);
            });

            if ($originalFilename) {
                $relativeOriginalFilename = $this->relativizePath($proxyFilename, $originalFilename);
                if ($relativeOriginalFilename[0] === '.') {
                    $generator->setRequiredFile(new RelativePath($relativeOriginalFilename));
                } else {
                    $generator->setRequiredFile($relativeOriginalFilename);
                }
            }
            $enhancer = new Enhancer($class, [], [
               $generator
            ]);
            $enhancer->setNamingStrategy($this->namingStrategy);
            $enhancer->writeClass($proxyFilename);
            $definition->setFile($proxyFilename);
            $definition->setClass($this->namingStrategy->getClassName($class));
            $definition->addMethodCall('__CGInterception__setLoader', [
               new Reference('jms_aop.interceptor_loader')
            ]);
        } else {
            $match = $this->compilationCache->getPointcutsMatch($this->pointcutsHash, $classFile);
            if (!$match) {
                return;
            }

            $this->addResources($class, $this->container);

            if ($class->isFinal()) {
                return;
            }

            $classNameMethods = $this->compilationCache->getClassNameMethods($this->pointcutsHash, $classFile);
            foreach ($classNameMethods as $className => $methodAdvices) {
                foreach ($methodAdvices as $method => $advices) {
                    $interceptors[$className][$method] = $advices;
                }
            }

            $classAdvices = $this->compilationCache->getClassAdvices($this->pointcutsHash, $classFile);

            if (empty($classAdvices)) {
                return;
            }

            $proxyFilename = $this->getProxyFilename($class);
            $proxyClassName = $this->namingStrategy->getClassName($class);
            $classAdvicesHash = md5(serialize($classAdvices));

            if (!file_exists($proxyFilename) || !$this->compilationCache->getProxyGenerated($proxyClassName, $classAdvicesHash)) {
                $generator = new InterceptionGenerator();
                $generator->setFilter(function (\ReflectionMethod $method) use ($classAdvices) {
                    return isset($classAdvices[$method->name]);
                });

                if ($originalFilename) {
                    $relativeOriginalFilename = $this->relativizePath($proxyFilename, $originalFilename);
                    if ($relativeOriginalFilename[0] === '.') {
                        $generator->setRequiredFile(new RelativePath($relativeOriginalFilename));
                    } else {
                        $generator->setRequiredFile($relativeOriginalFilename);
                    }
                }
                $enhancer = new Enhancer($class, [], [
                   $generator
                ]);
                $enhancer->setNamingStrategy($this->namingStrategy);
                $enhancer->writeClass($proxyFilename);

                $this->compilationCache->saveProxyGenerated($proxyClassName, $classAdvicesHash);
            }

            $definition->setFile($proxyFilename);
            $definition->setClass($proxyClassName);
            $definition->addMethodCall('__CGInterception__setLoader', [
               new Reference('jms_aop.interceptor_loader')
            ]);
        }
    }

    private function relativizePath($targetPath, $path)
    {
        $commonPath = dirname($targetPath);

        $level = 0;
        while ( ! empty($commonPath)) {
            if (0 === strpos($path, $commonPath)) {
                $relativePath = str_repeat('../', $level).substr($path, strlen($commonPath) + 1);

                return $relativePath;
            }

            $commonPath = dirname($commonPath);
            $level += 1;
        }

        return $path;
    }

    private function addResources(\ReflectionClass $class)
    {
        do {
            $this->container->addResource(new FileResource($class->getFilename()));
        } while (($class = $class->getParentClass()) && $class->getFilename());
    }

    private function getPointcuts()
    {
        if ($this->pointcuts !== null) {
            return $this->pointcuts;
        }

        $pointcuts = $pointcutReferences = array();

        foreach ($this->container->findTaggedServiceIds('jms_aop.pointcut') as $id => $attr) {
            if (!isset($attr[0]['interceptor'])) {
                throw new RuntimeException('You need to set the "interceptor" attribute for the "jms_aop.pointcut" tag of service "'.$id.'".');
            }

            $pointcutReferences[$attr[0]['interceptor']] = new Reference($id);
            $pointcuts[$attr[0]['interceptor']] = $this->container->get($id);
        }

        $this->container
            ->getDefinition('jms_aop.pointcut_container')
            ->addArgument($pointcutReferences)
        ;

        return $pointcuts;
    }


    private function shouldRecompile($pointcuts, ReflectionClass $class) {
        if ($this->compilationCache->hasClassModified($class)) {
            return true;
        }
        foreach ($pointcuts as $interceptor => $pointcut) {
            if ($this->compilationCache->hasClassModified(new ReflectionClass($pointcut))) {
                return true;
            }
        }

        return false;
    }

    private function getProxyFilename(ReflectionClass $class) {
        return $this->cacheDir . '/' . str_replace('\\', '-', $class->name) . '.php';
    }
}
