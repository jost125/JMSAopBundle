<?php

namespace JMS\AopBundle\DependencyInjection;

use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CompilationCache {

	private $cache;
	private $unitOfWork;
	private $loaded;

	public function __construct($cacheServiceId, ContainerInterface $containerInterface) {
		$this->cache = $cacheServiceId ? $containerInterface->get($cacheServiceId) : null;
		$this->unitOfWork = [];
		$this->loaded = false;
	}

	public function __destruct() {
		$this->flush();
	}

	public function load() {
		if (!$this->loaded) {
			$fetched = $this->cache->fetch('aop_compilation');
			$this->unitOfWork = is_array($fetched) ? $fetched : [];
			$this->loaded = true;
		}
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
		$this->save("class_advices", $pointcutsHash . $classFile, $classAdvices);
	}

	public function getClassAdvices($pointcutsHash, $classFile) {
		return $this->fetch("class_advices", $pointcutsHash . $classFile);
	}

	public function saveClassNameMethods($pointcutsHash, $classFile, array $classNameMethods) {
		$this->save("class_name_methods", $pointcutsHash . $classFile, $classNameMethods);
	}

	public function getClassNameMethods($pointcutsHash, $classFile) {
		return $this->fetch("class_name_methods", $pointcutsHash . $classFile);
	}

	public function saveProxyGenerated($proxyClassName, $classAdvicesHash) {
		$this->save("proxy_generated", $proxyClassName . $classAdvicesHash, true);
	}

	public function getProxyGenerated($proxyClassName, $classAdvicesHash) {
		return $this->fetch("proxy_generated", $proxyClassName . $classAdvicesHash);
	}

	private function fetch($prefix, $key) {
		return isset($this->unitOfWork[$prefix][$key]) ? $this->unitOfWork[$prefix][$key] : null;
	}

	private function save($prefix, $key, $value) {
		if (!isset($this->unitOfWork[$prefix])) {
			$this->unitOfWork[$prefix] = [$key => $value];
		}
		$this->unitOfWork[$prefix][$key] = $value;
	}

	public function flush() {
		if ($this->cache) {
			$this->cache->save('aop_compilation', $this->unitOfWork);
		}
	}

}
