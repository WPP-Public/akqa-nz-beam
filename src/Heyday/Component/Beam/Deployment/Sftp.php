<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Deployment\DeploymentProvider;
use Ssh\SshConfigFileConfiguration;
use Ssh\Session;

class Sftp implements DeploymentProvider
{
    protected $beam;
    protected $sftp;
    /**
     * @param Beam $beam
     * @return mixed
     */
    public function setBeam(Beam $beam)
    {
        $this->beam = $beam;
    }
    protected function getSftp()
    {
        if (null === $this->sftp) {
            // TODO: Error on non-absolute
            $server = $this->beam->getServer();

            $configuration = new SshConfigFileConfiguration(
                '~/.ssh/config',
                $server['host']
            );

            $session = new Session(
                $configuration,
                $configuration->getAuthentication(
                    null,
                    $server['user']
                )
            );

            $this->sftp = $session->getSftp();
        }
        return $this->sftp;
    }
    /**
     * @param callable $output
     * @param bool     $dryrun
     * @return mixed
     */
    public function up(\Closure $output = null, $dryrun = false)
    {
        $sftp = $this->getSftp();
        $files = $this->getFromFiles();

        $checksumfile = $this->getRemoteFilePath('checksums.json');
        $checksums = false;

        if (function_exists('bzdecompress') && $sftp->exists($checksumfile . '.bz2')) {
            $checksums = json_decode(
                bzdecompress($sftp->read($checksumfile . '.bz2')),
                true
            );
        } elseif (function_exists('gzinflate') && $sftp->exists($checksumfile . '.gz')) {
            $checksums = json_decode(
                gzinflate(substr($sftp->read($checksumfile . '.gz'), 10, -8)),
                true
            );
        } elseif ($sftp->exists($checksumfile)) {
            $checksums = json_decode($sftp->read($checksumfile), true);
        }

        $synclist = array();
        $changes = array();

        foreach ($files as $file) {
            $remotefile = $this->getRemoteFilePath($file);
            $relativefilename = $this->getRelativePath($file);
            if ($sftp->exists($remotefile)) {
                $remoteStat = $sftp->stat($remotefile);
                $localStat = stat($file);
                if ($checksums && $checksums[$remotefile] != md5_file($file)) {
                    $synclist[$file] = $remotefile;
                    $changes[] = array(
                        'update' => 'sent',
                        'filename' => $relativefilename,
                        'filetype' => 'file',
                        'reason' => array('checksum')
                    );
                } elseif ($remoteStat['size'] != $localStat['size']) {
                    $synclist[$file] = $remotefile;
                    $changes[] = array(
                        'update' => 'sent',
                        'filename' => $relativefilename,
                        'filetype' => 'file',
                        'reason' => array('size')
                    );
                }
            } else {
                $synclist[$file] = $remotefile;
                $changes[] = array(
                    'update' => 'created',
                    'filename' => $relativefilename,
                    'filetype' => 'file',
                    'reason' => array('notexist')
                );
            }
        }

        if (!$dryrun && !$this->beam->getOption('dry-run')) {
            foreach ($synclist as $localfile => $remotefile) {
                if (is_callable($output)) {
                    $output('out', "\n");
                }
                $dir = dirname($remotefile);
                if (!$sftp->exists($dir)) {
                    $sftp->mkdir($dir, 0755, true);
                }
                $sftp->send($localfile, $remotefile);
            }
        }

        return $changes;
    }
    /**
     * @param callable $output
     * @param bool     $dryrun
     * @return mixed
     */
    public function down(\Closure $output = null, $dryrun = false)
    {
        // TODO: Implement down() method.
    }

    protected function getRemoteFilePath($path)
    {
        $server = $this->beam->getServer();
        if ($path[0] == '/') {
            return str_replace($this->beam->getLocalPath(), $server['webroot'], $path);
        } else {
            return $server['webroot'] . '/' . $path;
        }
    }

    protected function getRelativePath($path)
    {
        return str_replace($this->beam->getLocalPath() . '/', '', $path);
    }

    protected function getFromFiles()
    {
        $files = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->beam->getLocalPath()),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if (in_array($file->getBasename(), array('.', '..')) || $file->isDir()) {
                continue;
            } elseif ($file->isFile() && !$this->isExcluded($this->getRelativePath($file))) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    protected function isExcluded($path)
    {
        $excludes = $this->beam->getConfig('exclude');
        foreach ($excludes as $exclude) {
            if ($exclude[0] == '/' && substr($exclude, -1) == '/') {
                if (strpos('/' . $path, $exclude) === 0) {
                    return true;
                }
            } elseif(substr($exclude, -1) == '/') {
                if (strpos('/' . $path, $exclude) !== false) {
                    return true;
                }
            } elseif(fnmatch('*' . $exclude, $path)) {
                return true;
            }
        }
        return false;
    }
    /**
     * @return mixed
     */
    public function getRemotePath()
    {

    }
}