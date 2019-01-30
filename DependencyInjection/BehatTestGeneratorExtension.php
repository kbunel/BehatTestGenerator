<?php

namespace BehatTestGenerator\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

class BehatTestGeneratorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
		$loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
		$loader->load('services.yaml');

		$configuration = new Configuration();
		$config = $this->processConfiguration($configuration, $configs);


		if (isset($config['fixtures'], $config['fixtures']['folder'])) {
			$fixturesDefinition = $container->getDefinition('BehatTestGenerator\Manager\FixturesManager');
			$fixturesDefinition->setArgument('$fixturesDirPath', $config['fixtures']['folder']);
		}

		if (isset($config['features'])) {
			$featureDefinition = $container->getDefinition('BehatTestGenerator\Manager\FeatureManager');

			if (isset($config['features']['authenticationEmails'])) {
				$featureDefinition->replaceArgument('$authenticationEmail', $config['features']['authenticationEmails']);
			}

			if (isset($config['features']['commonFixtures'])) {
				$featureDefinition->replaceArgument('$commonFixtures', $config['features']['commonFixtures']);
			}

			if (isset($config['features']['httpResponses'])) {
				$featureDefinition->replaceArgument('$httpResponses', $config['features']['httpResponses']);
			}
		}
    }
}
