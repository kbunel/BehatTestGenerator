<?php

namespace BehatTestGenerator\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('behat_test_generator');

        $rootNode
            ->children()
                ->arrayNode('fixtures')
                    ->children()
                        ->scalarNode('folder')->end()
                    ->end()
                ->end()
                ->arrayNode('features')
                    ->children()
                        ->scalarNode('commonFixtures')->end()
                        ->arrayNode('authenticationEmails')
                            ->children()
                                ->scalarNode('default')->end()
                            ->end()
                        ->end()
                        ->arrayNode('httpResponses')
                            ->children()
                                ->scalarNode('get')->end()
                                ->scalarNode('put')->end()
                                ->scalarNode('patch')->end()
                                ->scalarNode('post')->end()
                                ->scalarNode('delete')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}