<?php

namespace Heyday\Component\Beam\DeploymentProvider;

use Heyday\Component\Beam\DeploymentProvider\DeploymentResult;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\Process;

/**
 * Class Rsync
 * @package Heyday\Component\Beam\DeploymentProvider
 */
class Rsync extends Deployment implements DeploymentProvider
{
    /**
     * @var array
     */
    protected $options;
    /**
     * @param array $options
     */
    public function __construct(array $options)
    {
        $resolver = new OptionsResolver();
        $resolver->setOptional(
            array(
                'checksum',
                'delete',
                'archive',
                'compress',
                'delay-updates'
            )
        );
        $resolver->setAllowedTypes(
            array(
                'checksum'      => 'bool',
                'delete'        => 'bool',
                'archive'       => 'bool',
                'compress'      => 'bool',
                'delay-updates' => 'bool'
            )
        );
        $resolver->setDefaults(
            array(
                'checksum'      => true,
                'delete'        => false,
                'archive'       => true,
                'compress'      => true,
                'delay-updates' => true
            )
        );
        $this->options = $resolver->resolve($options);
    }
    /**
     * @{inheritDoc}
     */
    public function up(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        return $this->deploy(
            $this->buildCommand(
                $this->beam->getLocalPath(),
                $this->getTargetPath(),
                $dryrun
            ),
            $output
        );
    }
    /**
     * @{inheritDoc}
     */
    public function down(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        return $this->deploy(
            $this->buildCommand(
                $this->getTargetPath(),
                $this->beam->getLocalPath(),
                $dryrun
            ),
            $output
        );
    }
    /**
     * @param                    $command
     * @param  callable          $output
     * @return DeploymentResult
     * @throws \RuntimeException
     */
    protected function deploy($command, \Closure $output = null)
    {
        $this->generateExcludesFile();
        $process = $this->getProcess($command);
        $process->run($output);
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }
        $output = $process->getOutput();

        return new DeploymentResult($this->formatOutput($output));
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

        $flags = 'rlpD'; // recursion, links, perms, devices, specials

        $command = array(
            array(
                'rsync %s/ %s',
                $fromPath,
                $toPath
            ),
            '-' . $flags,
            '--itemize-changes'
        );

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
        }
        if ($this->options['delete']) {
            $command[] = '--delete';
        }
        if ($this->options['compress']) {
            $command[] = '--compress';
        }
        if ($this->options['delay-updates']) {
            $command[] = '--delay-updates';
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
     * @param $path
     * @return array|string
     */
    protected function parsePathSteps($path)
    {
        $steps = array();
        $folders = explode('/', $path);
        $lastIndex = count($folders) - 1;

        $partialPath = '';
        foreach ($folders as $index => $component) {
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
            '/(?:(^\*[\w]+)|([<>.ch])([fdLDS])([.?+c][.?+s][.?+tT][.?+p][.?+o][.?+g][.?+]?[.?+a]?[.?+x]?)) (.*)/',
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
            if ($reason === array('time')) {
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

        return sprintf(
            '%s@%s:%s',
            $server['user'],
            $server['host'],
            $server['webroot']
        );
    }
    /**
     * @return mixed|null
     */
    public function getLimitations()
    {
        return null;
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
}
