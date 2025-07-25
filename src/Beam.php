<?php

namespace Heyday\Beam;

use Heyday\Beam\Config\ValueInterpolator;
use Heyday\Beam\DeploymentProvider\DeploymentProvider;
use Heyday\Beam\DeploymentProvider\DeploymentResult;
use Heyday\Beam\Exception\Exception;
use Heyday\Beam\Exception\InvalidArgumentException;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\VcsProvider\Git;
use Heyday\Beam\VcsProvider\GitLikeVcsProvider;
use Heyday\Beam\VcsProvider\VcsProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\Process;

/**
 * Class Beam
 * @package Heyday\Component
 */
class Beam
{
    /**
     * @var array
     */
    protected $config;
    /**
     * @var array
     */
    protected $options;
    /**
     * @var bool
     */
    protected $prepared = false;

    /**
     * An config in the format defined in BeamConfiguration
     *
     * @param array $config
     * @param array $options
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function __construct(
        array $config,
        array $options
    ) {
        // Perform initial setup and validation
        $this->config = $config;
        $this->setup($options);

        // Apply variable interpolation config after initial checks
        $this->config = $this->replaceConfigVariables($config);
    }

    /**
     * Uses the options resolver to set the options to the object from an array
     *
     * Any dynamic options are set in this method and then validated manually
     * This method can be called multiple times, each time it is run it will validate
     * the array provided and set the appropriate options.
     *
     * This might be useful if you prep the options for a command via a staged process
     * for example an interactive command line tool
     * @param  $options
     * @throws InvalidArgumentException
     */
    public function setup($options)
    {
        $this->options = $this->getOptionsResolver()->resolve($options);

        if (!$this->isWorkingCopy() && !$this->getVCSProvider()->exists()) {
            throw new InvalidArgumentException("You can't use beam without a vcs.");
        }

        if (!$this->isWorkingCopy() && !$this->options['ref']) {
            if ($this->isServerLocked()) {
                $this->options['ref'] = $this->getServerLockedBranch();
            } else {
                $this->options['ref'] = $this->getVCSProvider()->getCurrentBranch();
            }
        }

        $this->validateSetup();
    }

    /**
     * Validates dynamic options or options that the options resolver can't validate
     * @throws InvalidArgumentException
     */
    protected function validateSetup()
    {
        // Prevent a server with empty options being used
        $server = $this->getServer();

        $emptyKeys = [];
        if (empty($server['webroot'])) {
            $emptyKeys[] = 'webroot';
        }
        if (empty($server['hosts']) && empty($server['host'])) {
            $emptyKeys[] = 'host';
            $emptyKeys[] = 'hosts';
        }

        if (count($emptyKeys)) {
            $options = implode(', ', $emptyKeys);
            throw new InvalidArgumentException(sprintf(
                "The server '%s' has empty values for required options: %s",
                $this->options['target'],
                $options
            ));
        }

        if ($this->options['ref']) {
            // TODO: Allow refs from the same branch (ie master~1) when locked. Git can show what branches a ref is
            // in by using: git branch --contains [ref]
            if ($this->isServerLocked() && $this->options['ref'] !== $this->getServerLockedBranch()) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Specified ref "%s" doesn\'t match the locked branch "%s"',
                        $this->options['ref'],
                        $this->getServerLockedBranch()
                    )
                );
            }

            if (!$this->getVCSProvider()->isValidRef($this->options['ref'])) {
                $branches = $this->getVCSProvider()->getAvailableBranches();
                throw new InvalidArgumentException(
                    sprintf(
                        'Ref "%s" is not valid. Available branches are: %s',
                        $this->options['ref'],
                        '\'' . implode('\', \'', $branches) . '\''
                    )
                );
            }
        }

        if ($this->isWorkingCopy()) {
            if ($this->isTargetLockedRemote()) {
                throw new InvalidArgumentException('Working copy can\'t be used with a locked remote branch');
            }
        } else {
            if (!is_writable($this->getLocalPathFolder())) {
                throw new InvalidArgumentException(
                    sprintf('The local path "%s" is not writable', $this->getLocalPathFolder())
                );
            }
        }

        $limitations = $this->getDeploymentProvider()->getLimitations();

        if (is_array($limitations)) {
            // Check if remote commands defined when not available
            if ($this->hasRemoteCommands() && in_array(DeploymentProvider::LIMITATION_REMOTECOMMAND, $limitations)) {
                throw new InvalidConfigurationException(sprintf(
                    "Commands are to run on the location 'target' but '%s' cannot execute remote commands.",
                    $server['type']
                ));
            }
        }
    }

    /**
     * @param \Heyday\Beam\DeploymentProvider\DeploymentResult $deploymentResult
     * @param \Closure|null $deploymentCallback
     * @return mixed
     * @throws Exception
     * @throws \Exception
     */
    public function doRun(?DeploymentResult $deploymentResult = null, $deploymentCallback = null)
    {
        if ($this->isUp()) {
            $this->prepareLocalPath();
            $this->runPreTargetCommands();
            $deploymentResult = $this->getDeploymentProvider()->up(
                $this->options['deploymentoutputhandler'],
                false,
                $deploymentResult
            );

            if (is_callable($deploymentCallback)) {
                $deploymentCallback();
            }

            if (!$this->isWorkingCopy()) {
                $this->runPostLocalCommands();
            }
            $this->runPostTargetCommands();
        } else {
            $deploymentResult = $this->getDeploymentProvider()->down(
                $this->options['deploymentoutputhandler'],
                false,
                $deploymentResult
            );
        }

        return $deploymentResult;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function doDryrun()
    {
        if ($this->isUp()) {
            $this->prepareLocalPath();
            $deploymentResult = $this->getDeploymentProvider()->up(
                $this->options['deploymentoutputhandler'],
                true
            );
        } else {
            $deploymentResult = $this->getDeploymentProvider()->down(
                $this->options['deploymentoutputhandler'],
                true
            );
        }

        return $deploymentResult;
    }

    /**
     * Ensures that the correct content is at the local path
     * @throws \Exception
     */
    protected function prepareLocalPath()
    {
        if (!$this->isPrepared() && !$this->isWorkingCopy() && !$this->isDown()) {
            $this->runOutputHandler(
                $this->options['outputhandler'],
                [
                    'Preparing local deploy path'
                ]
            );

            if ($this->isTargetLockedRemote()) {
                $this->runOutputHandler(
                    $this->options['outputhandler'],
                    [
                        'Updating remote branch'
                    ]
                );

                $this->getVCSProvider()->updateBranch($this->options['ref']);
            }

            $this->runOutputHandler(
                $this->options['outputhandler'],
                [
                    'Exporting ref'
                ]
            );
            $this->getVCSProvider()->exportRef(
                $this->options['ref'],
                $this->getLocalPath()
            );

            $this->setPrepared(true);

            $this->runPreLocalCommands();
            $this->writeLog();
        }
    }

    public function configureDeploymentProvider(InputInterface $input, OutputInterface $output)
    {
        $this->getDeploymentProvider()->configure($input, $output);
    }

    /**
     * Gets the from location for rsync
     *
     * Takes the form "path"
     * @return string
     */
    public function getLocalPath()
    {
        if ($this->isWorkingCopy() || $this->isDown()) {
            $path = $this->options['srcdir'];
        } else {
            $path = sprintf(
                '%s/%s',
                $this->getTempDir(),
                $this->getLocalPathname()
            );
        }

        return sprintf(
            '%s',
            $path
        );
    }

    public function getTempDir(): string
    {
        if (!isset($this->options['tmpdir'])) {
            return '/tmp';
        }

        return $this->options['tmpdir'] ? rtrim($this->options['tmpdir'], '/') : '/tmp';
    }

    /**
     * @return string
     */
    public function getLocalPathname()
    {
        return sprintf(
            'beam-%s',
            md5($this->options['srcdir'])
        );
    }

    /**
     * @return mixed
     */
    public function getTargetPath()
    {
        return $this->getDeploymentProvider()->getTargetAsText();
    }

    /**
     * @return array
     */
    public function getTargetPaths()
    {
        return $this->getDeploymentProvider()->getTargetPaths();
    }

    /**
     * @param boolean $prepared
     */
    public function setPrepared($prepared)
    {
        $this->prepared = $prepared;
    }

    /**
     * @param $key
     * @param $value
     * @return void
     */
    public function setOption($key, $value)
    {
        $this->options = $this->getOptionsResolver()->resolve(
            array_merge(
                $this->options,
                [
                    $key => $value
                ]
            )
        );
    }

    /**
     * @return boolean
     */
    public function isPrepared()
    {
        return $this->prepared;
    }

    /**
     * Returns whether or not files are being sent to the target
     * @return bool
     */
    public function isUp()
    {
        if (!isset($this->options['direction'])) {
            return false;
        }

        return $this->options['direction'] === 'up';
    }

    /**
     * Returns whether or not files are being sent to the local
     * @return bool
     */
    public function isDown()
    {
        if (!isset($this->options['direction'])) {
            return false;
        }

        return $this->options['direction'] === 'down';
    }

    /**
     * Returns whether or not beam is operating from a working copy
     * @return mixed
     */
    public function isWorkingCopy()
    {
        if (!isset($this->options['working-copy'])) {
            return false;
        }

        return $this->options['working-copy'];
    }

    /**
     * Returns whether or not the server is locked to a branch
     * @return bool
     */
    public function isServerLocked()
    {
        $server = $this->getServer();

        return isset($server['branch']) && $server['branch'];
    }

    /**
     * Returns whether or not the server is locked to a remote branch
     * @return bool
     */
    public function isTargetLockedRemote()
    {
        $server = $this->getServer();

        return $this->isServerLocked() && $this->getVCSProvider()->isRemote($server['branch']);
    }

    /**
     * Returns whether or not the branch is remote
     * @return bool
     */
    public function isBranchRemote()
    {
        return $this->getVCSProvider()->isRemote($this->options['ref']);
    }

    /**
     * A helper method for determining if beam is operating with a list of paths
     * @return bool
     */
    public function hasPath()
    {
        return is_array($this->options['path']) && count($this->options['path']) > 0;
    }

    /**
     * Get the server config we are deploying to.
     *
     * This method is guaranteed to to return a server due to the options resolver and config
     */
    public function getServer(): array
    {
        return $this->config['servers'][$this->options['target']];
    }

    /**
     * Get all host names for a server
     *
     * @param array|null $server Optional server config
     * @return array|string[]
     */
    public function getHosts($server = null): array
    {
        if (!$server) {
            $server = $this->getServer();
        }

        $hosts = [];
        if (isset($server['host'])) {
            $hosts[] = $server['host'];
        }
        if (isset($server['hosts'])) {
            $hosts = array_merge($hosts, $server['hosts']);
        }
        return $hosts;
    }

    /**
     * Get primary (first) host
     *
     * @param array|null $server Optional server config
     * @return string
     */
    public function getPrimaryHost($server = null)
    {
        $hosts = $this->getHosts($server);
        return reset($hosts);
    }

    /**
     * Get the locked branch
     * @return mixed
     */
    public function getServerLockedBranch()
    {
        $server = $this->getServer();

        return $this->isServerLocked() ? $server['branch'] : false;
    }


    public function getLocalPathFolder(): string
    {
        return dirname($this->options['srcdir']);
    }

    /**
     * A helper method that returns a process with some defaults
     * @param          $commandline
     * @param  null    $cwd
     * @param  int     $timeout
     * @return Process
     */
    public function getProcess($commandline, $cwd = null, $timeout = null): Process
    {
        if (!$timeout) {
            $timeout = 3600;
        }

        return Process::fromShellCommandline(
            $commandline,
            $cwd ? $cwd : $this->options['srcdir'],
            null,
            null,
            $timeout
        );
    }

    /**
     * @param $option
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function getOption($option)
    {
        if (array_key_exists($option, $this->options)) {
            return $this->options[$option];
        } else {
            throw new InvalidArgumentException(
                sprintf(
                    'Option \'%s\' doesn\'t exist',
                    $option
                )
            );
        }
    }

    /**
     * @param $config
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function getConfig($config)
    {
        if (array_key_exists($config, $this->config)) {
            return $this->config[$config];
        } else {
            throw new InvalidArgumentException(
                sprintf(
                    'Config \'%s\' doesn\'t exist',
                    $config
                )
            );
        }
    }

    /**
     * Check if the deployment provider implements an interface
     * @param $interfaceName
     * @return bool
     * @throws InvalidArgumentException
     */
    public function deploymentProviderImplements($interfaceName)
    {
        $interfaces = class_implements(
            get_class($this->getOption('deploymentprovider'))
        );
        return isset($interfaces[$interfaceName]);
    }

    /**
     * Set the deployment provider's result stream handler
     * This is only available if the deployment provider implements the
     * DeploymentProvider\ResultStream interface.
     * @throws InvalidArgumentException
     */
    public function setResultStreamHandler(?\Closure $handler = null)
    {
        $this->getOption('deploymentprovider')->setStreamHandler($handler);
    }

    /**
     * A helper method that runs a process and checks its success, erroring if it failed
     * @param  Process  $process
     * @param  \Closure|null $output
     * @throws RuntimeException
     */
    protected function runProcess(Process $process, ?\Closure $output = null)
    {
        $process->run($output);
        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }
    }

    /**
     * Runs commands specified in config in the pre phase on the local
     */
    protected function runPreLocalCommands()
    {
        $this->runCommands(
            $this->getFilteredCommands('pre', 'local'),
            'Running local pre-deployment commands',
            'runLocalCommand'
        );
    }

    /**
     * Runs commands specified in config in the pre phase on the target
     */
    protected function runPreTargetCommands()
    {
        $this->runCommands(
            $this->getFilteredCommands('pre', 'target'),
            'Running target pre-deployment commands',
            'runTargetCommand'
        );
    }

    /**
     * Runs commands specified in config in the post phase on the local
     */
    protected function runPostLocalCommands()
    {
        $this->runCommands(
            $this->getFilteredCommands('post', 'local'),
            'Running local post-deployment commands',
            'runLocalCommand'
        );
    }

    /**
     * Runs commands specified in config in the post phase on the target
     */
    protected function runPostTargetCommands()
    {
        $this->runCommands(
            $this->getFilteredCommands('post', 'target'),
            'Running target post-deployment commands',
            'runTargetCommand'
        );
    }

    /**
     * @param $commands
     * @param $message
     * @param $method
     */
    protected function runCommands($commands, $message, $method)
    {
        if (count($commands)) {
            $this->runOutputHandler(
                $this->options['outputhandler'],
                [
                    $message
                ]
            );
            foreach ($commands as $command) {
                $this->$method($command);
            }
        }
    }

    /**
     * @param $phase
     * @param $location
     * @return array
     */
    protected function getFilteredCommands($phase, $location)
    {
        $commands = [];
        foreach ($this->config['commands'] as $command) {
            if ($command['phase'] !== $phase) {
                continue;
            }
            if ($command['location'] !== $location) {
                continue;
            }
            if (count($command['servers']) !== 0 && !in_array($this->options['target'], $command['servers'])) {
                continue;
            }
            if (!$command['required']) {
                if ($command['tag'] && (count($this->options['command-tags']) === 0 || !$this->matchTag($command['tag']))) {
                    continue;
                }
                if (is_callable($this->options['commandprompthandler']) && !$this->options['commandprompthandler']($command)) {
                    continue;
                }
            }
            $commands[] = $command;
        }

        return $commands;
    }

    /**
     * Checks if a tag matches one passed on the command line.
     * Wildcard matching is supported supported
     * @param $tag
     * @return bool
     */
    protected function matchTag($tag)
    {
        foreach ($this->options['command-tags'] as $pattern) {
            if (fnmatch($pattern, $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   $command
     * @throws RuntimeException
     */
    protected function runTargetCommand($command)
    {
        $this->runOutputHandler(
            $this->options['outputhandler'],
            [
                $command['command'],
                'command:target'
            ]
        );

        $server = $this->getServer();

        $userComponent = isset($server['user']) && $server['user'] <> '' ? $server['user'] . '@' : '';

        $remoteCmd = sprintf(
            'cd \'%s\' && %s',
            $server['webroot'],
            $command['command']
        );

        foreach ($this->getHosts() as $host) {
            $args = [
                // SSHPASS is set in \Heyday\Beam\DeploymentProvider\Rsync
                getenv('SSHPASS') === false ? 'ssh' : 'sshpass -e ssh',
                $command['tty'] ? '-t' : '',
                $userComponent . $host,
                escapeshellcmd($remoteCmd)
            ];

            $command['command'] = implode(' ', $args);

            $this->doExecCommand($command, $this->options['targetcommandoutputhandler']);
        }
    }

    /**
     * @param   $command
     * @throws RuntimeException
     */
    protected function runLocalCommand($command)
    {
        $this->runOutputHandler(
            $this->options['outputhandler'],
            [
                $command['command'],
                'command:local'
            ]
        );

        $this->doExecCommand($command, $this->options['localcommandoutputhandler']);
    }

    /**
     * @param $command
     * @param $outputHandler
     * @throws RuntimeException
     */
    protected function doExecCommand($command, $outputHandler)
    {
        try {
            $process = null;

            if ($command['tty']) {
                passthru(sprintf(
                    '%s; %s 2>&1',
                    "cd {$this->getLocalPath()}",
                    $command['command']
                ), $exit);

                if ($exit !== 0) {
                    throw new RuntimeException("Command returned a non-zero exit status ($exit)");
                }
            } else {
                $process = $this->getProcess(
                    $command['command'],
                    $this->getLocalPath(),
                );

                $this->runProcess(
                    $process,
                    $outputHandler
                );
            }
        } catch (RuntimeException $exception) {
            if (!$this->promptCommandFailureContinue($command, $exception, $process)) {
                exit(1);
            }
        }
    }

    /**
     * @throws RuntimeException
     * @return mixed
     */
    protected function promptCommandFailureContinue($command, $exception, ?Process $process = null)
    {
        if (!is_callable($this->options['commandfailurehandler'])) {
            throw $exception;
        }

        return $this->options['commandfailurehandler']($command, $exception, $process);
    }

    /**
     * @param $handler
     * @param $arguments
     * @return bool|mixed
     */
    protected function runOutputHandler($handler, $arguments)
    {
        if (is_callable($handler)) {
            return call_user_func_array($handler, $arguments);
        }

        return false;
    }

    /**
     * Replace variable placeholders in config fields
     *
     * @param array $config
     * @return array
     * @throws Exception
     */
    protected function replaceConfigVariables(array $config)
    {
        $vcs = $this->getVCSProvider();

        if ($vcs instanceof GitLikeVcsProvider) {
            $interpolator = new ValueInterpolator($vcs, $this->getOption('ref'), [
                'target' => $this->getOption('target')
            ]);
            return $interpolator->process($config);
        } else {
            throw new Exception('Config interpolation is only possible using a Git-like VCS');
        }
    }

    /**
     * This returns an options resolver that will ensure required options are set and that all options set are valid
     *
     * @return OptionsResolver
     */
    protected function getOptionsResolver()
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired(
            [
                'direction',
                'target',
                'srcdir',
                'deploymentprovider'
            ]
        )->setDefined(
            [
                'ref',
                'path',
                'dry-run',
                'tmpdir',
                'working-copy',
                'command-tags',
                'vcsprovider',
                'deploymentprovider',
                'deploymentoutputhandler',
                'localcommandoutputhandler',
                'targetcommandoutputhandler',
                'outputhandler'
            ]
        )->setAllowedValues(
            'direction',
            ['up', 'down']
        )->setAllowedValues(
            'target',
            array_keys($this->config['servers'])
        )->setDefaults(
            [
                'ref'                        => '',
                'path'                       => [],
                'tmpdir'                     => '/tmp',
                'dry-run'                    => false,
                'working-copy'               => false,
                'command-tags'               => [],
                'vcsprovider'                => function (Options $options) {
                    return new Git($options['srcdir']);
                },
                'deploymentoutputhandler'    => null,
                'outputhandler'              => null,
                'localcommandoutputhandler'  => null,
                'targetcommandoutputhandler' => null,
                'commandprompthandler'       => null,
                'commandfailurehandler'      => null
            ]
        )
            ->setAllowedTypes('ref', 'string')
            ->setAllowedTypes('srcdir', 'string')
            ->setAllowedTypes('dry-run', 'bool')
            ->setAllowedTypes('tmpdir', 'string')
            ->setAllowedTypes('working-copy', 'bool')
            ->setAllowedTypes('command-tags', 'array')
            ->setAllowedTypes('vcsprovider', __NAMESPACE__ . '\VcsProvider\VcsProvider')
            ->setAllowedTypes('deploymentprovider', __NAMESPACE__ . '\DeploymentProvider\DeploymentProvider');

        // Configure option normalizers
        // This was previously done with a fluid interface, but Symfony Console 3.x removes support for that
        foreach ($this->getOptionNormalizers() as $optionName => $normalizer) {
            $resolver->setNormalizer($optionName, $normalizer);
        }

        return $resolver;
    }

    /**
     * Return a map of option names to normalization functions
     *
     * @return Callable[]
     */
    protected function getOptionNormalizers()
    {
        $that = $this;

        return [
            'ref'                        => function (Options $options, $value) {
                return trim($value);
            },
            'path'                       => function (Options $options, $value) {
                return is_array($value) ? array_map(function ($value) {
                    return trim($value, '/');
                }, $value) : false;
            },
            'deploymentprovider'         => function (Options $options, $value) use ($that) {
                if (is_callable($value)) {
                    $value = $value($options);
                }
                $value->setBeam($that);

                return $value;
            },
            'deploymentoutputhandler'    => function (Options $options, $value) {
                if ($value !== null && !is_callable($value)) {
                    throw new InvalidArgumentException('Deployment output handler must be null or callable');
                }

                return $value;
            },
            'outputhandler'              => function (Options $options, $value) {
                if ($value !== null && !is_callable($value)) {
                    throw new InvalidArgumentException('Output handler must be null or callable');
                }

                return $value;
            },
            'localcommandoutputhandler'  => function (Options $options, $value) {
                if ($value !== null && !is_callable($value)) {
                    throw new InvalidArgumentException('Local command output handler must be null or callable');
                }

                return $value;
            },
            'targetcommandoutputhandler' => function (Options $options, $value) {
                if ($value !== null && !is_callable($value)) {
                    throw new InvalidArgumentException('Target command output handler must be null or callable');
                }

                return $value;
            },
            'commandprompthandler'       => function (Options $options, $value) {
                if ($value !== null && !is_callable($value)) {
                    throw new InvalidArgumentException('Command prompt handler must be null or callable');
                }

                return $value;
            },
            'commandfailurehandler'      => function (Options $options, $value) {
                if ($value !== null && !is_callable($value)) {
                    throw new InvalidArgumentException('Command failure handler must be null or callable');
                }

                return $value;
            }
        ];
    }

    /**
     * Returns true if any commands to run on the remote ("target") are defined
     * @return boolean
     */
    protected function hasRemoteCommands()
    {
        if (!isset($this->config['commands'])) {
            return false;
        }

        if (!is_array($this->config['commands'])) {
            return false;
        }

        foreach ($this->config['commands'] as $command) {
            if ($command['location'] === 'target') {
                if (empty($command['tag'])) {
                    return true;
                } else {
                    return $this->matchTag($command['tag']);
                }
            }
        }

        return false;
    }

    /**
     *
     */
    protected function writeLog()
    {
        file_put_contents(
            $this->getLocalPath() . '/.beamlog',
            $this->getVCSProvider()->getLog($this->options['ref'])
        );
    }

    /**
     * Get configured deployment provider
     *
     * @return DeploymentProvider
     */
    protected function getDeploymentProvider()
    {
        return $this->options['deploymentprovider'];
    }

    /**
     * Get configured VCS provider
     *
     * @return VcsProvider
     */
    protected function getVCSProvider()
    {
        return $this->options['vcsprovider'];
    }
}
