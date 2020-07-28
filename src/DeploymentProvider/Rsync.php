<?php

namespace Heyday\Beam\DeploymentProvider;

use Closure;
use Heyday\Beam\Config\DeploymentResultConfiguration;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Utils;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\Process;

/**
 * Class Rsync
 * @package Heyday\Beam\DeploymentProvider
 */
class Rsync extends Deployment implements DeploymentProvider, ResultStream
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var Closure
     */
    protected $resultStreamHandler;

    /**
     * @param array $options
     */
    public function __construct(array $options)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined(
            array(
                'checksum',
                'delete',
                'archive',
                'compress',
                'delay-updates',
                'args'
            )
        );
        $resolver->setAllowedTypes(
            array(
                'checksum'      => 'bool',
                'delete'        => 'bool',
                'archive'       => 'bool',
                'compress'      => 'bool',
                'delay-updates' => 'bool',
                'args'          => 'string'
            )
        );
        $resolver->setDefaults(
            array(
                'checksum'      => true,
                'delete'        => false,
                'archive'       => true,
                'compress'      => true,
                'delay-updates' => true,
                'args'          => ''
            )
        );
        $this->options = $resolver->resolve($options);
    }

    /**
     * @inheritdoc
     */
    public function configure(InputInterface $input, OutputInterface $output)
    {
        // Prompt for password if server config specifies to use sshpass
        if ($this->isUsingSshPass()) {
            $formatterHelper = new FormatterHelper();
            $questionHelper = new QuestionHelper();
            $serverName = $this->beam->getOption('target');

            if (!Utils::command_exists('sshpass')) {
                throw new RuntimeException("$serverName is configured to use sshpass but the sshpass program wasn't found on your path.");
            }

            $question = new Question(
                $formatterHelper->formatSection(
                    'Prompt',
                    Utils::getQuestion("Enter password for $serverName:"),
                    'comment'
                ),
                false
            );

            $question->setHidden(true);

            $password = $questionHelper->ask($input, $output, $question);

            // Set the password variable for sshpass and the remote shell variable for rsync so sshpass is used
            putenv("SSHPASS={$password}");
            putenv("RSYNC_RSH=sshpass -e ssh");
        }
    }

    /**
     * Beam up will beam to all target hosts
     *
     * @param Closure          $output
     * @param bool             $dryrun
     * @param DeploymentResult $deploymentResult
     * @return DeploymentResult
     * @throws RuntimeException
     */
    public function up(Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        /** @var DeploymentResult $mergedResult */
        $mergedResult = null;
        $results = [];
        // silent per-server output when collating multiple streams
        $silent = count($this->getTargetPaths()) > 1;
        foreach ($this->getTargetPaths() as $server => $targetPath) {
            $result = $this->deploy(
                $this->buildCommand(
                    $this->beam->getLocalPath(),
                    $targetPath,
                    $dryrun
                ),
                $output,
                $silent
            );
            $result->setName($server);
            $results[] = $result;

            // Merge all results
            if (!$mergedResult) {
                $mergedResult = $result;
            } else {
                $mergedResult = $this->combineResults($mergedResult, $result);
            }
        }

        // Render merged output here
        if ($this->resultStreamHandler) {
            $handler = $this->resultStreamHandler;
            $handler($mergedResult);
        }

        return $mergedResult;
    }

    /**
     * Beam down only beams down from the master host
     *
     * @param Closure          $output
     * @param bool             $dryrun
     * @param DeploymentResult $deploymentResult
     * @return DeploymentResult
     * @throws RuntimeException
     */
    public function down(Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        return $this->deploy(
            $this->buildCommand(
                $this->getTargetPath(),
                $this->beam->getLocalPath(),
                $dryrun
            ),
            $output,
            false
        );
    }

    /**
     * @param string  $command
     * @param Closure $output
     * @param bool    $silent
     * @return DeploymentResult
     * @throws RuntimeException
     */
    protected function deploy($command, Closure $output = null, $silent = false)
    {
        $this->generateExcludesFile();
        $outputHandler = $this->getOutputStreamHandler($output, $silent);
        $process = $this->getProcess($command);
        $process->run($outputHandler);

        if (!$process->isSuccessful()) {
            $errorMessage = $process->getErrorOutput();

            if ($this->isUsingSshPass() && $process->getExitCode() == 12) {
                $errorMessage .= "\n(This error can be caused by an incorrect password when using the sshpass option.)\n";
            }

            throw new RuntimeException($errorMessage);
        }

        if ($this->resultStreamHandler && $outputHandler) {
            return new DeploymentResult($outputHandler('fetch'));
        } else {
            $output = $process->getOutput();
            return new DeploymentResult($this->formatOutput($output));
        }
    }

    /**
     * Builds the rsync command based of current options
     * @param         $fromPath
     * @param         $toPath
     * @param  bool   $dryrun
     * @return string
     */
    protected function buildCommand($fromPath, $toPath, $dryrun = false)
    {
        $server = $this->beam->getServer();

        $flags = 'rlD'; // recursion, links, devices, specials

        $command = array(
            array(
                'rsync %s/ %s',
                $fromPath,
                $toPath
            ),
            '-' . $flags,
            '--itemize-changes'
        );

        // Sync permissions if enabled for the target
        if ($server['syncPermissions']) {
            $command[] = '--perms';
        }

        if ($this->options['args'] !== '') {
            $command[] = $this->options['args'];
        }

        if ($this->beam->hasPath()) {
            $paths = $this->beam->getOption('path');
            $excludes = array();
            $includes = array();

            foreach ($paths as $path) {
                $steps = $this->parsePathSteps($path);
                $last = count($steps) - 1;

                foreach ($steps as $index => $step) {
                    $includes[] = $step;

                    if ($index != $last) {
                        $excludes[] = "$step/*";
                    }
                }
            }

            // Exclude everything else
            $excludes[] = '/*';

            foreach ($includes as $include) {
                $command[] = array(
                    '--include="%s"',
                    $include
                );
            }

            foreach ($excludes as $exclude) {
                $command[] = array(
                    '--exclude="%s"',
                    $exclude
                );
            }
        }

        if ($dryrun) {
            $command[] = '--dry-run';
        }
        if ($this->options['checksum']) {
            $command[] = '--checksum';
        } else {
            $command[] = '--size-only';
        }
        if ($this->options['delete']) {
            $command[] = '--delete';
        }
        if ($this->options['compress']) {
            $command[] = '--compress';
        }
        if ($this->options['delay-updates']) {
            $command[] = '--delay-updates';

            if ($this->options['delete']) {
                $command[] = $this->rsyncVersionCompare('>=', '3.0.0') ? '--delete-delay' : '--delete-after';
            }
        }

        $command[] = array(
            '--exclude-from="%s"',
            $this->getExcludesPath()
        );

        foreach ($command as $key => $part) {
            if (is_array($part)) {
                $command[$key] = call_user_func_array('sprintf', $part);
            }
        }

        return implode(' ', $command);
    }

    /**
     * Get the version of the rsync program that will be used for transfer
     *
     * @return string
     * @throws RuntimeException
     */
    protected function getRsyncVersion()
    {
        $process = new Process('rsync --version');
        $process->run();

        list($version) = sscanf($process->getOutput(), 'rsync version %s');

        if (!$version) {
            throw new RuntimeException("Couldn't check rsync version. Is rsync installed and on your PATH?");
        }

        return $version;
    }

    /**
     * Compare the available version number of rsync against a value
     *
     * @param string $comparison - see version_compare $operator argument docs
     * @param string $version
     * @return bool
     * @throws RuntimeException
     */
    protected function rsyncVersionCompare($comparison, $version)
    {
        return version_compare($this->getRsyncVersion(), $version, $comparison);
    }

    /**
     * Return if the sshpass program is to be used
     * @return bool
     */
    protected function isUsingSshPass()
    {
        if ($this->beam) {
            $server = $this->beam->getServer();
            return $server['sshpass'];
        }
    }

    /**
     * @param $path
     * @return array|string
     */
    protected function parsePathSteps($path)
    {
        $steps = array();
        $folders = explode('/', $path);

        $partialPath = '';
        foreach ($folders as $component) {
            if (!empty($component)) {
                $partialPath .= '/' . $component;
                $steps[] = $partialPath;
            }
        }

        return $steps;
    }

    /**
     * @param $line
     * @return array|bool
     */
    protected function parseLine($line)
    {
        $change = array();
        $matches = array();
        if (1 !== preg_match(
                '/
                (?:
                    (^\*\w+) # capture anything with a "*" then words e.g. "*deleting"
                    | # or
                    ([<>.ch]) # capture update mode
                    ([fdLDS]) # capture filetype
                    (
                        [.?+c]  # checksum
                        [.?+s]  # size
                        [.?+tT] # time
                        [.?+p]  # permissions
                        [.?+o]  # owner
                        [.?+g]  # group
                        [.?+]?  # no meaning
                        [.?+a]? # optional aclextended
                        [.?+x]? # optional extended
                        [.?+n]? # work around for a bug in rsync 3.1.1 when installed on OSX via Homebrew
                    )
                )
                [ ] # a space
                (.*) # filename
            /x',
                $line,
                $matches
            )) {
            return false;
        }
        if ($matches[1] == '*deleting') {
            $change['update'] = 'deleted';
            $change['filename'] = $matches[5];
            $change['filetype'] = preg_match('/\/$/', $matches[5]) ? 'directory' : 'file';
            $change['reason'] = array('missing');
        } else {
            switch ($matches[2]) {
                case '<':
                    $change['update'] = 'sent';
                    break;
                case '>':
                    $change['update'] = 'received';
                    break;
                case 'c':
                    $change['update'] = 'created';
                    break;
                case 'h':
                    $change['update'] = 'link';
                    break;
                case '.':
                    $change['update'] = 'attributes';
                    break;
            }
            switch ($matches[3]) {
                case 'f':
                    $change['filetype'] = 'file';
                    break;
                case 'd':
                    $change['filetype'] = 'directory';
                    break;
                case 'L':
                    $change['filetype'] = 'symlink';
                    break;
                case 'D':
                    $change['filetype'] = 'device';
                    break;
                case 'S':
                    $change['filetype'] = 'special';
                    break;
            }
            $reason = array();
            if ($matches[4][0] == 'c') {
                $reason[] = 'checksum';
            } elseif ($matches[4][0] == '+') {
                $reason[] = 'new';
            }
            if ($matches[4][1] == 's') {
                $reason[] = 'size';
            }
            if ($matches[4][2] == 't') {
                $reason[] = 'time';
            }
            if ($matches[4][3] == 'p') {
                $reason[] = 'permissions';
            }
            if ($matches[4][4] == 'o') {
                $reason[] = 'owner';
            }
            if ($matches[4][5] == 'g') {
                $reason[] = 'group';
            }
            if (isset($matches[4][7]) && $matches[4][7] == 'a') {
                $reason[] = 'acl';
            }
            if (isset($matches[4][8]) && $matches[4][8] == 'x') {
                $reason[] = 'extended';
            }
            if ($reason === array('time') || (!count($reason) && $matches[4][2] == 'T')) {
                return false;
            }
            $change['reason'] = $reason;
            $change['filename'] = $matches[5];
        }

        return $change;
    }

    /**
     * @param $output
     * @return array
     */
    public function formatOutput($output)
    {
        $changes = array();
        foreach (explode(PHP_EOL, $output) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $change = $this->parseLine($line);
                if ($change) {
                    $changes[] = $change;
                }
            }
        }

        return $changes;
    }

    /**
     * Generate the excludes file
     */
    protected function generateExcludesFile()
    {
        $excludes = $this->beam->getConfig('exclude');
        if ($this->beam->hasPath()) {
            $idx = array_search(
                $this->beam->getOption('path'),
                $excludes
            );
            if ($idx !== false) {
                unset($excludes[$idx]);
            }
        }
        file_put_contents(
            $this->getExcludesPath(),
            implode(PHP_EOL, $excludes) . PHP_EOL
        );
    }

    /**
     * Get the path to the excludes file
     * @return string
     */
    protected function getExcludesPath()
    {
        return sprintf(
            '/tmp/%s.excludes',
            $this->beam->getLocalPathname()
        );
    }

    /**
     * Gets the to location for rsync
     *
     * Takes the form: "user@host:path"
     * @return string
     */
    public function getTargetPath()
    {
        $server = $this->beam->getServer();
        $host = $this->beam->getPrimaryHost($server);

        return $this->buildPath($host, $server);
    }

    /**
     * Gets the to location for rsync for all hostnames (supports multiple hosts)
     * Key is server name / url
     *
     * @return array
     */
    public function getTargetPaths()
    {
        $paths = [];
        $server = $this->beam->getServer();
        foreach ($this->beam->getHosts() as $host) {
            $paths[$host] = $this->buildPath($host, $server);
        }
        return $paths;
    }

    /**
     * Return a string representation of the target
     * @return string
     */
    public function getTargetAsText()
    {
        return $this->getTargetPath();
    }

    /**
     * @return mixed|null
     */
    public function getLimitations()
    {
        return null;
    }

    /**
     * Set a callback function to receive a stream of changes
     * @param Closure $callback function(array $array)
     */
    public function setStreamHandler(Closure $callback = null)
    {
        $this->resultStreamHandler = $callback;
    }

    /**
     * Create a callback to handle the rsync process output
     *
     * @see setStreamHandler
     * @param Closure $callback
     * @param bool    $silent Force stream handler to capture, but not output
     * @return callable
     */
    protected function getOutputStreamHandler(Closure $callback = null, $silent = false)
    {
        $streamHandler = $silent ? null : $this->resultStreamHandler;
        $buffer = '';
        $results = [];
        return function ($type, $data = "\n") use (&$buffer, &$results, $streamHandler, $callback) {
            // Ignore error output
            if ($type === Process::ERR) {
                return null;
            }

            if ($callback) {
                $callback(substr_count($data, "\n"));
            }

            // If a stream output handler is set, parse the partial change set
            $buffer .= $data;
            $lastNewLine = strrpos($buffer, "\n");
            if ($lastNewLine !== false) {
                $data = substr($buffer, 0, $lastNewLine);
                $buffer = substr($buffer, $lastNewLine);

                $result = $this->formatOutput($data);
                $results[] = $result;

                // Pass through, unless silenced
                if ($streamHandler) {
                    $streamHandler($result);
                }
            }

            // Return the collected results when asked
            if ($type === 'fetch') {
                return call_user_func_array('array_merge', $results);
            }
        };
    }

    /**
     * @param $command
     * @return Process
     */
    protected function getProcess($command)
    {
        $process = new Process(
            $command,
            null,
            null,
            null,
            null
        );

        return $process;
    }

    /** Helper to build a path based on a hostname and server config array
     *
     * @param string $host
     * @param array  $server
     * @return string
     */
    protected function buildPath($host, $server)
    {
        $hostPath = sprintf(
            '%s:%s',
            $host,
            $server['webroot']
        );

        if (isset($server['user']) && $user = $server['user']) {
            $hostPath = $user . '@' . $hostPath;
        }

        return $hostPath;
    }

    /**
     * @param DeploymentResult $left
     * @param DeploymentResult $right
     * @return DeploymentResult
     */
    protected function combineResults($left, $right)
    {
        $result = [];
        // Map left result by filename
        foreach ($left as $leftItem) {
            $result[$leftItem['filename']] = $leftItem;
        }
        // Merge in right item
        foreach ($right as $rightItem) {
            $filename = $rightItem['filename'];
            if (isset($result[$filename])) {
                $result[$filename] = $this->combineResultRows(
                    $result[$filename],
                    $rightItem,
                    $right->getConfiguration()
                );
            } else {
                $result[$filename] = $rightItem;
            }
        }
        $result = new DeploymentResult(array_values($result));
        $result->setNestedResults(array_merge(
            $left->getNestedResults(),
            $right->getNestedResults()
        ));
        return $result;
    }

    /**
     * If multiple servers have different update type, pick the best one to display in the output log
     *
     * @param array                         $left
     * @param array                         $right
     * @param DeploymentResultConfiguration $config
     * @return array
     */
    protected function combineResultRows($left, $right, $config)
    {
        // Build result object
        $result = array_merge($left, $right);

        // Get all distinct reasons
        $result['reason'] = array_unique(array_merge($left['reason'], $right['reason']));

        // Pick best update (first matching item in getUpdates())
        foreach ($config->getUpdates() as $update) {
            if ($left['update'] === $update || $right['update'] === $update) {
                $result['update'] = $update;
                break;
            }
        }

        // Count number of nodes this item is changed on
        $leftNodes = isset($left['nodes']) ? $left['nodes'] : 1;
        $rightNodes = isset($right['nodes']) ? $right['nodes'] : 1;
        $result['nodes'] = $leftNodes + $rightNodes;

        return $result;
    }
}
