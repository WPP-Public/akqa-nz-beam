<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;
use Symfony\Component\Process\Process;

/**
 * Class Rsync
 * @package Heyday\Component\Beam\Deployment
 */
class Rsync implements DeploymentProvider
{
    /**
     *
     */
    const TIMEOUT = 300;
    /**
     * @var \Heyday\Component\Beam\Beam
     */
    protected $beam;
    /**
     * @param \Heyday\Component\Beam\Beam $beam
     */
    public function setBeam(Beam $beam)
    {
        $this->beam = $beam;
    }
    /**
     * @param  callable          $output
     * @param  bool              $dryrun
     * @throws \RuntimeException
     * @return array
     */
    public function deploy(\Closure $output = null, $dryrun = false)
    {
        $this->generateExcludesFile();
        $process = new Process(
            $this->buildCommand($dryrun),
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

        return $this->parseOutput($output);
    }
    /**
     * @param $output
     * @return array
     */
    protected function parseOutput($output)
    {
        $changes = array();
        foreach (explode(PHP_EOL, $output) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $change = array();
                $matches = array();
                preg_match('/(?:(\*deleting){1} (.*))?(?:([<>.ch]{1})([fdLDS]{1})([.?+cstTpogz]{7}) (.*))?/', $line, $matches);
                if ($matches[1] == '*deleting') {
                    $change['update'] = 'deleted';
                    $change['filename'] = $matches[2];
                    $change['filetype'] = preg_match('/\/$/', $matches[2]) ? 'directory' : 'file';
                    $change['reason'] = array('notexist');
                } else {
                    switch ($matches[3]) {
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
                    switch ($matches[4]) {
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
                    if ($matches[5][0] == 'c') {
                        $reason[] = 'checksum';
                    } elseif ($matches[5][0] == '+') {
                        $reason[] = 'new';
                    }
                    if ($matches[5][1] == 's') {
                        $reason[] = 'size';
                    }
                    if ($matches[5][2] == 't') {
                        $reason[] = 'time';
                    }
                    if ($matches[5][3] == 'p') {
                        $reason[] = 'permissions';
                    }
                    if ($matches[5][4] == 'o') {
                        $reason[] = 'owner';
                    }
                    if ($matches[5][5] == 'g') {
                        $reason[] = 'group';
                    }
                    $change['reason'] = $reason;
                    $change['filename'] = $matches[6];
                }
                $changes[] = $change;
            }
        }

        return $changes;
    }
    /**
     * Builds the rsync command based of current options
     * @param bool $dryrun
     * @internal param bool $forcedryrun
     * @return string
     */
    protected function buildCommand($dryrun = false)
    {
        if ($this->beam->isUp()) {
            $fromPath = $this->beam->getLocalPath();
            $toPath = $this->getRemotePath();
        } else {
            $toPath = $this->beam->getLocalPath();
            $fromPath = $this->getRemotePath();
        }

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
        return dirname($this->beam->getOption('srcdir')) .
            DIRECTORY_SEPARATOR .
            $this->beam->getOption('excludesfile');
    }
    /**
     * Gets the to location for rsync
     *
     * Takes the form: "user@host:path"
     * @return string
     */
    public function getRemotePath()
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
