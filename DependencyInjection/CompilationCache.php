<?php

namespace JMS\AopBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerInterface;

class CompilationCache {

	private $cache;

	public function __construct($cacheServiceId, ContainerInterface $containerInterface) {
		$this->cache = $containerInterface->get($cacheServiceId);
	}

}
