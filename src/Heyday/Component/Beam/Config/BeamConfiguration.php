<?php

namespace Heyday\Component\Beam\Config;

use Heyday\Component\Beam\Exception\Exception;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
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
     * Patterns that should not be transferred
     * @var array
     */
    protected static $defaultExcludes = array(
        '*~',
        '.DS_Store',
        'beam.json',
        'checksums.json*',
        '.svn/',
        '.git/',
        '.gitignore',
        '.gitmodules',
        '.hg/',
        '.hgignore',
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

        $resolver->resolve(
            array(
                'target' => $input->getArgument('target')
            )
        );
    }
    /**
     * Parse a raw beam config
     * (Includes loading imports and validation)
     * @param array $config
     * @return array
     */
    public static function parseConfig(array $config)
    {
        if (isset($config['import']) && is_array($config['import'])) {
            $configs = BeamConfiguration::loadImports($config['import']);
            array_unshift($configs, $config);
        } else {
            $configs = array($config);
        }

        $processor = new Processor();

        return $processor->processConfiguration(
            new BeamConfiguration(),
            $configs
        );
    }
    /**
     * Load the contents of the files and URLs in $imports, recursively
     * @param array $imports - list of files/urls to load
     * @param array $imported - list of files/urls already loaded
     * @return array
     */
    public static function loadImports(array $imports, array &$imported = array())
    {
        $configs = array();

        foreach ($imports as $import) {

            if (in_array($import, $imported)) {
                continue;
            }

            $import = static::processPath($import);
            $json = json_decode(file_get_contents($import), true);

            if ($json) {
                $configs[] = $json;
                $imported[] = $import;

                if (isset($json['import'])) {
                    $configs = array_merge($configs, self::loadImports($json['import'], $imported));
                }

            } else if (json_last_error() != JSON_ERROR_NONE) {
                throw new InvalidConfigurationException(
                    "Failed to parse JSON for '$import'. Check for syntax errors."
                );

            } else {
                throw new Exception("Import '$import' parsed to nothing.");
            }
        }

        return $configs;
    }
    /**
     * Replaces '~'' with users home path
     * @param  string $path
     * @return string
     */
    protected static function processPath($path)
    {
        if ($path[0] === '~') {
            $path = substr_replace($path, getenv('HOME'), 0, 1);
        }

        return $path;
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
                ->arrayNode('import')
                    ->prototype('scalar')->end()
                ->end()
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
        $value =  $value ? $value : array(
            'patterns' => array()
        );

        return array_merge(static::$defaultExcludes, $value['patterns']);
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
