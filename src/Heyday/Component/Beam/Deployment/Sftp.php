<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Deployment\DeploymentProvider;
use Heyday\Component\Beam\Utils;
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
        $rootpath = $this->beam->getLocalPath();

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
            $path = $file->getPathname();
            $remotefile = $this->getRemoteFilePath($path);
            $relativefilename = Utils::getRelativePath($rootpath, $path);
            if ($sftp->exists($remotefile)) {
                $remoteStat = $sftp->stat($remotefile);
                $localStat = stat($path);
                if ($checksums && $checksums[$relativefilename] != md5_file($path)) {
                    $synclist[$path] = $remotefile;
                    $changes[] = array(
                        'update' => 'sent',
                        'filename' => $relativefilename,
                        'filetype' => 'file',
                        'reason' => array('checksum')
                    );
                } elseif ($remoteStat['size'] != $localStat['size']) {
                    $synclist[$path] = $remotefile;
                    $changes[] = array(
                        'update' => 'sent',
                        'filename' => $relativefilename,
                        'filetype' => 'file',
                        'reason' => array('size')
                    );
                }
            } else {
                $synclist[$path] = $remotefile;
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

    protected function getFromFiles()
    {
        $excludes = $this->beam->getConfig('exclude');
        $rootpath = $this->beam->getLocalPath();
        $files = Utils::getAllFiles(
            function ($file) use ($excludes, $rootpath) {
                return $file->isFile() && !Utils::isExcluded(
                    $excludes,
                    Utils::getRelativePath(
                        $rootpath,
                        $file->getPathname()
                    )
                );
            },
            $this->beam->getLocalPath()
        );
        return $files;
    }
    /**
     * @return mixed
     */
    public function getRemotePath()
    {

    }
}