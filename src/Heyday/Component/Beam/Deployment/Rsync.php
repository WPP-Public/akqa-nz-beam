<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Deployment\DeploymentResult;
use Symfony\Component\Process\Process;

/**
 * Class Rsync
 * @package Heyday\Component\Beam\Deployment
 */
class Rsync extends Deployment implements DeploymentProvider
{
    /**
     *
     */
    const TIMEOUT = 300;
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
     * @param  bool              $dryrun
     * @return array
     * @throws \RuntimeException
     */
    protected function deploy($command, \Closure $output = null)
    {
        $this->generateExcludesFile();
        $process = new Process(
            $command,
            null,
            null,
            null,
            self::TIMEOUT
        );
        $process->run($output);
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }
        $output = $process->getOutput();

        return new DeploymentResult($this->formatOutput($output));
    }
    /**
     * Builds the rsync command based of current options
     * @param bool $dryrun
     * @internal param bool $forcedryrun
     * @return string
     */
    protected function buildCommand($fromPath, $toPath, $dryrun = false)
    {

        $command = array(
            array(
                'rsync %s/ %s',
                $fromPath,
                $toPath
            ),
            '--itemize-changes',
            array(
                '--exclude-from="%s"',
                $this->getExcludesPath()
            )
        );

        if ($dryrun || $this->beam->getOption('dry-run')) {
            $command[] = '--dry-run';
        }
        if ($this->beam->getOption('checksum')) {
            $command[] = '--checksum';
        }
        if ($this->beam->getOption('delete')) {
            $command[] = '--delete';
        }
        if ($this->beam->getOption('archive')) {
            $command[] = '--archive';
        }
        if ($this->beam->getOption('compress')) {
            $command[] = '--compress';
        }
        if ($this->beam->getOption('delay-updates')) {
            $command[] = '--delay-updates';
        }

        if ($this->beam->hasPath()) {
            $folders = explode('/', $this->beam->getOption('path'));
            $allFolders = '';
            foreach ($folders as $folder) {
                if (!empty($folder)) {
                    $allFolders .= '/' . $folder;
                    $exclude = substr($allFolders, 0, strrpos($allFolders, '/'));
                    $command[] = array(
                        '--include="%s/" --exclude="%s/*"',
                        $allFolders,
                        $exclude
                    );
                }
            }
        }

        foreach ($command as $key => $part) {
            if (is_array($part)) {
                $command[$key] = call_user_func_array('sprintf', $part);
            }
        }

        return implode(' ', $command);
    }
    /**
     * @param $output
     * @return array
     */
    protected function formatOutput($output)
    {
        $changes = array();
        foreach (explode(PHP_EOL, $output) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $change = array();
                $matches = array();
                preg_match(
                    '/(?:(^\*[\w]+)|([<>.ch])([fdLDS])([.?+c][.?+s][.?+t][.?+p][.?+o][.?+g][.?+]?[.?+a]?[.?+x]?)) (.*)/',
                    $line,
                    $matches
                );
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
                            $change['update'] = 'nochange';
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
                    $change['reason'] = $reason;
                    $change['filename'] = $matches[5];
                }
                $changes[] = $change;
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
}
