<?php

namespace BehatTestGenerator\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

class BehatTestGeneratorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
		$loader = new YamlFileLoader(
        	$container,
        	new FileLocator(__DIR__.'/config')
    	);
    	$loader->load('services.yaml');
    }
}
