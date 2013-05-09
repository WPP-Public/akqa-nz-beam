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
            $server = $this->beam->getServer();

            if ($server['webroot'][0] !== '/') {
                throw new \RuntimeException('Webrrot must be a absolute path when using sftp');
            }

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
        $dir = $this->beam->getLocalPath();

        $files = Utils::getAllowedFilesFromDirectory(
            $this->beam->getConfig('exclude'),
            $dir
        );

        $targetchecksumfile = $this->getTargetFilePath('checksums.json');
        $targetchecksums = array();

        if (function_exists('bzdecompress') && $sftp->exists($targetchecksumfile . '.bz2')) {
            $targetchecksums = Utils::checksumsFromBz2($sftp->read($targetchecksumfile . '.bz2'));
        } elseif (function_exists('gzinflate') && $sftp->exists($targetchecksumfile . '.gz')) {
            $targetchecksums = Utils::checksumsFromGz($sftp->read($targetchecksumfile . '.gz'));
        } elseif ($sftp->exists($targetchecksumfile)) {
            $targetchecksums = json_decode($sftp->read($targetchecksumfile), true);
        }

        $synclist = array();
        $changes = array();
        $localchecksums = Utils::getChecksumForFiles($files, $dir);

        foreach ($files as $file) {
            $path = $file->getPathname();
            $targetfile = $this->getTargetFilePath($path);
            $relativefilename = Utils::getRelativePath($dir, $path);
            if ($sftp->exists($targetfile)) {
                $remoteStat = $sftp->stat($targetfile);
                $localStat = stat($path);
                if (isset($targetchecksums[$relativefilename]) && $targetchecksums[$relativefilename] !== $localchecksums[$relativefilename]) {
                    $synclist[$path] = $targetfile;
                    $changes[] = array(
                        'update' => 'sent',
                        'filename' => $relativefilename,
                        'filetype' => 'file',
                        'reason' => array('checksum')
                    );
                } elseif ($remoteStat['size'] != $localStat['size']) {
                    $synclist[$path] = $targetfile;
                    $changes[] = array(
                        'update' => 'sent',
                        'filename' => $relativefilename,
                        'filetype' => 'file',
                        'reason' => array('size')
                    );
                }
            } else {
                $synclist[$path] = $targetfile;
                $changes[] = array(
                    'update' => 'created',
                    'filename' => $relativefilename,
                    'filetype' => 'file',
                    'reason' => array('notexist')
                );
            }
        }

        if (!$dryrun && !$this->beam->getOption('dry-run')) {
            foreach ($synclist as $localfile => $targetfile) {
                if (is_callable($output)) {
                    $output('out', "\n");
                }
                $dir = dirname($targetfile);
                if (!$sftp->exists($dir)) {
                    $sftp->mkdir($dir, 0755, true);
                }
                $sftp->send($localfile, $targetfile);
            }
            // Save the checksums to the server
            $sftp->write($this->getTargetFilePath('checksums.json.bz2'), Utils::checksumsToBz2($localchecksums));
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

    protected function getTargetFilePath($path)
    {
        $server = $this->beam->getServer();
        if ($path[0] == '/') {
            return str_replace($this->beam->getLocalPath(), $server['webroot'], $path);
        } else {
            return $server['webroot'] . '/' . $path;
        }
    }
    /**
     * @return mixed
     */
    public function getTargetPath()
    {
        $server = $this->beam->getServer();
        return $server['webroot'];
    }
}