<?php

namespace Heyday\Component\Beam\Config;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Class BeamConfiguration
 * @package Heyday\Component\Beam\Config
 */
class BeamConfiguration extends Configuration implements ConfigurationInterface
{
    /**
     * @var array
     */
    public static $transferMethods = array(
        'rsync' => '\Heyday\Component\Beam\TransferMethod\RsyncTransferMethod',
        'ftp' => '\Heyday\Component\Beam\TransferMethod\FtpTransferMethod',
        'sftp' => '\Heyday\Component\Beam\TransferMethod\SftpTransferMethod',
    );
    /**
     * @var array
     */
    protected $applications = array(
        '_base'        => array(
            '*~',
            '.DS_Store',
            '.gitignore',
            '.mergesources.yml',
            'README.md',
            'composer.json',
            'composer.lock',
            'deploy.json',
            'beam.json',
            'deploy.properties',
            'sftp-config.json',
            'checksums.json*',
            '/access-logs/',
            '/cgi-bin/',
            '/.idea/',
            '.svn/',
            '.git/',
            '/maintenance/on'
        ),
        'gear'         => array(
            '/images/repository/'
        ),
        'silverstripe' => array(
            '/assets/',
            '/silverstripe-cache/',
            '/assets-generated/',
            '/cache-include/cache/',
            '/heyday-cacheinclude/cache/',
            '/silverstripe-cacheinclude/cache/'
        ),
        'symfony'      => array(
            '/cache/',
            '/data/lucene/',
            '/config/log/',
            '/data/lucene/',
            '/lib/form/base/',
            '/lib/model/map/',
            '/lib/model/om/',
            '/log/',
            '/web/uploads/'
        ),
        'wordpress'    => array(
            'wp-content/uploads/'
        ),
        'zf'           => array(
            '/www/uploads/',
            '/web/uploads/'
        )
    );
    /**
     * @var array
     */
    protected $phases = array(
        'pre',
        'post'
    );
    /**
     * @var array
     */
    protected $locations = array(
        'local',
        'target'
    );

    /**
     * Validate user input against a config
     * @param InputInterface $input
     * @param array $config
     */
    public static function validateArguments(InputInterface $input, $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(
            array(
                'target'
            )
        )->setAllowedValues(
            array(
                'target' => array_keys($config['servers'])
            )
        );

        $resolver->resolve(array(
            'target' => $input->getArgument('target')
        ));
    }
    /**
     * Generates the configuration tree builder.
     *
     * @return \Symfony\Component\Config\Definition\Builder\TreeBuilder The tree builder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('beam');
        $self = $this;

        $rootNode
            ->children()
                ->arrayNode('servers')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->prototype('array')
                        ->prototype('scalar')->end()
                    ->end()
                    ->validate()
                    ->always(
                        function ($v) use ($self) {
                            foreach ($v as $name => $config) {
                                if (empty($config['type'])) {
                                    $config['type'] = 'rsync';
                                }
                                $configTree = $self->getServerTypeTree($name, $config['type']);
                                $v[$name] = $configTree->finalize($configTree->normalize($config));
                            }

                            return $v;
                        }
                    )
                    ->end()
                ->end()
                ->arrayNode('commands')
                    ->prototype('array')
                        ->children()
                            ->scalarNode('command')->isRequired()->end()
                            ->scalarNode('phase')
                                ->isRequired()
                                ->validate()
                                    ->ifNotInArray($this->phases)
                                    ->thenInvalid(
                                        'Phase "%s" is not valid, options are: ' .
                                            $this->getFormattedOptions($this->phases)
                                    )
                                ->end()
                            ->end()
                            ->scalarNode('location')
                                ->isRequired()
                                ->validate()
                                    ->ifNotInArray($this->locations)
                                    ->thenInvalid(
                                        'Location "%s" is not valid, options are: ' .
                                            $this->getFormattedOptions($this->locations)
                                    )
                                ->end()
                            ->end()
                            ->arrayNode('servers')
                                ->prototype('scalar')->end()
                            ->end()
                            ->scalarNode('required')->defaultFalse()->end()
                            ->scalarNode('tag')->defaultFalse()->end()
                            ->scalarNode('tty')->defaultFalse()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('exclude')
                    ->children()
                        ->arrayNode('applications')
                            ->prototype('scalar')
                                ->validate()
                                    ->ifNotInArray(array_keys($this->applications))
                                    ->thenInvalid(
                                        'Application "%s" is not valid, options are: ' .
                                            $this->getFormattedOptions(array_keys($this->applications))
                                    )
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('patterns')
                            ->prototype('scalar')->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->always(
                            function ($v) use ($self) {
                                return $self->buildExcludes($v);
                            }
                        )
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(
                    function ($v) use ($self) {
                        foreach ($v['commands'] as $commandName => $command) {
                            foreach ($command['servers'] as $server) {
                                if (!isset($v['servers'][$server])) {
                                    throw new InvalidConfigurationException(
                                        "Command \"{$commandName}\" references an invalid server, options are: " .
                                            $self->getFormattedOptions(array_keys($v['servers']))
                                    );
                                }
                            }
                        }

                        if (!isset($v['exclude'])) {
                            $v['exclude'] = $self->buildExcludes(false);
                        }

                        return $v;
                    }
                )
            ->end();

        return $treeBuilder;
    }
    /**
     * @param $value
     * @return array
     */
    public function buildExcludes($value)
    {
        $excludes = array();

        if (!$value) {
            $value = array(
                'applications' => array(),
                'patterns'     => array()
            );
        }

        if (!in_array('_base', $value['applications'])) {
            $value['applications'][] = '_base';
        }

        foreach ($value['applications'] as $application) {
            $excludes = array_merge($excludes, $this->applications[$application]);
        }

        return array_merge($excludes, $value['patterns']);
    }
    /**
     * @param $name
     * @param $type
     * @return \Symfony\Component\Config\Definition\NodeInterface
     */
    public function getServerTypeTree($name, $type)
    {
        $name = str_replace('.', '_', $name);
        $typeTreeBuilder = new TreeBuilder();
        $typeTreeBuilder->root($name)
            ->children()
                ->enumNode('type')
                ->values(array_keys(static::$transferMethods))->isRequired()
                ->end()
            ->end();

        $typeTree = $typeTreeBuilder->buildTree();
        $typeTree->finalize($typeTree->normalize(array('type' => $type)));

        $treeBuilder = new TreeBuilder();
        $node = $treeBuilder->root($name)
            ->children()
                ->scalarNode('type')->isRequired()->end()
                ->scalarNode('host')->isRequired()->end()
                ->scalarNode('user')->isRequired()->end()
                ->scalarNode('branch')->end();

        switch ($type) {
            case 'sftp':
            case 'ftp':
                $node->scalarNode('webroot')->isRequired()
                    ->validate()
                    ->always(
                        function ($v) use ($type) {
                            if ($v[0] !== '/') {
                                throw new InvalidConfigurationException(
                                    sprintf(
                                        'Webroot must be a absolute path when using "%s"',
                                        $type
                                    )
                                );
                            }

                            if (strlen($v) > 1) {
                                $v = rtrim($v, '/');
                            }

                            return $v;
                        }
                    )->end()
                    ->end()
                    ->scalarNode('password')->isRequired()->end();

                if ($type == 'ftp') {
                    $node->scalarNode('passive')->defaultFalse()->end()
                        ->scalarNode('ssl')->defaultFalse()->end();
                }
                break;
            case 'rsync':
                $node->scalarNode('webroot')
                    ->isRequired()
                        ->validate()
                        ->always(
                            function ($v) {
                                return rtrim($v, '/');
                            }
                        )
                        ->end();
                break;
        }

        // end children
        $node->end();

        return $treeBuilder->buildTree();
    }
}
