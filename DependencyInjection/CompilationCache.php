<?php

namespace JMS\AopBundle\DependencyInjection;

use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CompilationCache {

	private $cache;

	public function __construct($cacheServiceId, ContainerInterface $containerInterface) {
		$this->cache = $containerInterface->get($cacheServiceId);
	}

	public function hasClassModified(ReflectionClass $class) {
		$classFile = $class->getFileName();
		$currentLastModifiedTime = filemtime($classFile);
		$cacheLastModifiedTime = $this->fetch("modified_file", $classFile);

		$this->save("modified_file", $classFile, $currentLastModifiedTime);

		return $currentLastModifiedTime != $cacheLastModifiedTime;
	}

	public function savePointcutsMatch($pointcutsHash, $classFile, $match) {
		$this->save("pointcuts_match", $pointcutsHash . $classFile, $match);
	}

	public function getPointcutsMatch($pointcutsHash, $classFile) {
		return $this->fetch("pointcuts_match", $pointcutsHash . $classFile);
	}

	public function saveClassAdvices($pointcutsHash, $classFile, array $classAdvices) {
		$this->save("class_advices", $pointcutsHash . $classFile, serialize($classAdvices));
	}

	public function getClassAdvices($pointcutsHash, $classFile) {
		return unserialize($this->fetch("class_advices", $pointcutsHash . $classFile));
	}

	public function saveClassNameMethods($pointcutsHash, $classFile, array $classNameMethods) {
		$this->save("class_name_methods", $pointcutsHash . $classFile, serialize($classNameMethods));
	}

	public function getClassNameMethods($pointcutsHash, $classFile) {
		return unserialize($this->fetch("class_name_methods", $pointcutsHash . $classFile));
	}

	public function saveProxyGenerated($proxyClassName, $classAdvicesHash) {
		$this->save("class_name_methods", $proxyClassName . $classAdvicesHash, true);
	}

	public function getProxyGenerated($proxyClassName, $classAdvicesHash) {
		return $this->fetch("class_name_methods", $proxyClassName . $classAdvicesHash);
	}

	private function fetch($prefix, $key) {
		return $this->cache->fetch($prefix . "|" . $key);
	}

	private function save($prefix, $key, $value) {
		$this->cache->save($prefix . "|" . $key, $value);
	}

}
