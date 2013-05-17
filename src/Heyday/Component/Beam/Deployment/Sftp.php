<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Deployment\DeploymentProvider;
use Heyday\Component\Beam\Deployment\DeploymentResult;
use Heyday\Component\Beam\Utils;
use Ssh\Authentication\Password;
use Ssh\Configuration;
use Ssh\Session;
use Ssh\SshConfigFileConfiguration;

/**
 * Class Sftp
 * @package Heyday\Component\Beam\Deployment
 */
class Sftp extends Deployment implements DeploymentProvider
{
    /**
     * @var
     */
    protected $fullmode;
    /**
     * @var
     */
    protected $sftp;
    /**
     * @param bool $fullmode
     */
    public function __construct($fullmode = false)
    {
        $this->fullmode = $fullmode;
    }
    /**
     * @return \Ssh\Sftp
     * @throws \RuntimeException
     */
    protected function getSftp()
    {
        if (null === $this->sftp) {
            $server = $this->beam->getServer();

            if ($server['webroot'][0] !== '/') {
                throw new \RuntimeException('Webrrot must be a absolute path when using sftp');
            }

            if (isset($server['password'])) {
                $configuration = new Configuration(
                    $server['host']
                );

                $session = new Session(
                    $configuration,
                    new Password($server['user'], $server['password'])
                );
            } else {
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
            }

            $this->sftp = $session->getSftp();
        }

        return $this->sftp;
    }
    /**
     * @param  callable         $output
     * @param  bool             $dryrun
     * @param  DeploymentResult $deploymentResult
     * @return mixed
     */
    public function up(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        // TODO: implement delete
        $dir = $this->beam->getLocalPath();

        $sftp = $this->getSftp();

        $files = Utils::getAllowedFilesFromDirectory(
            $this->beam->getConfig('exclude'),
            $dir . ($this->beam->hasPath() ? '/' . $this->beam->getOption('path') : '')
        );

        $localchecksums = Utils::checksumsFromFiles($files, $dir);

        $targetchecksums = array();

        $targetchecksumfile = $this->getTargetFilePath('checksums.json');

        if (function_exists('bzdecompress') && $sftp->exists($targetchecksumfile . '.bz2')) {
            $targetchecksums = Utils::checksumsFromBz2($sftp->read($targetchecksumfile . '.bz2'));
        } elseif (function_exists('gzinflate') && $sftp->exists($targetchecksumfile . '.gz')) {
            $targetchecksums = Utils::checksumsFromGz($sftp->read($targetchecksumfile . '.gz'));
        } elseif ($sftp->exists($targetchecksumfile)) {
            $targetchecksums = Utils::checksumsFromString($sftp->read($targetchecksumfile));
        }

        $targetchecksums = Utils::getFilteredChecksums(
            $this->beam->getConfig('exclude'),
            $targetchecksums
        );

        if (null === $deploymentResult) {

            $result = array();

            foreach ($files as $file) {
                $path = $file->getPathname();
                $targetfile = $this->getTargetFilePath($path);
                $relativefilename = Utils::getRelativePath($dir, $path);

                if ($this->fullmode) {

                    if ($sftp->exists($targetfile)) {
                        if (isset($targetchecksums[$relativefilename]) && $targetchecksums[$relativefilename] !== $localchecksums[$relativefilename]) {
                            $result[] = array(
                                'update'        => 'sent',
                                'filename'      => $targetfile,
                                'localfilename' => $path,
                                'filetype'      => 'file',
                                'reason'        => array('checksum')
                            );
                        } else {
                            $targetStat = $sftp->stat($targetfile);
                            $localStat = stat($path);
                            if ($targetStat['size'] != $localStat['size']) {
                                $result[] = array(
                                    'update'        => 'sent',
                                    'filename'      => $targetfile,
                                    'localfilename' => $path,
                                    'filetype'      => 'file',
                                    'reason'        => array('size')
                                );
                            }
                        }
                    } else {
                        $result[] = array(
                            'update'        => 'created',
                            'filename'      => $targetfile,
                            'localfilename' => $path,
                            'filetype'      => 'file',
                            'reason'        => array('missing')
                        );
                    }

                } else {

                    if (isset($targetchecksums[$relativefilename])) {
                        if ($targetchecksums[$relativefilename] !== $localchecksums[$relativefilename]) {
                            $result[] = array(
                                'update'        => 'sent',
                                'filename'      => $targetfile,
                                'localfilename' => $path,
                                'filetype'      => 'file',
                                'reason'        => array('checksum')
                            );
                        }
                    } else {
                        $result[] = array(
                            'update'        => 'created',
                            'filename'      => $targetfile,
                            'localfilename' => $path,
                            'filetype'      => 'file',
                            'reason'        => array('missing')
                        );
                    }

                }
            }

            $deploymentResult = new DeploymentResult($result);

        }

        if (!$dryrun) {
            foreach ($deploymentResult as $change) {
                if (is_callable($output)) {
                    $output();
                }
                $dir = dirname($change['filename']);
                if (!$sftp->exists($dir)) {
                    $sftp->mkdir($dir, 0755, true);
                }
                $sftp->send($change['localfilename'], $change['filename']);
            }
            // Save the checksums to the server
            $sftp->write(
                $this->getTargetFilePath('checksums.json.bz2'),
                Utils::checksumsToBz2(
                    $this->beam->hasPath() ? array_merge(
                        $targetchecksums,
                        $localchecksums
                    ) : $localchecksums
                )
            );
        }

        return $deploymentResult;
    }
    /**
     * @param  callable          $output
     * @param  bool              $dryrun
     * @param  DeploymentResult  $deploymentResult
     * @throws \RuntimeException
     * @return mixed
     */
    public function down(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        // TODO: Implement down() method.
        throw new \RuntimeException('Not implemented');
    }

    /**
     * @param $path
     * @return mixed|string
     */
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
    /**
     * @return array
     */
    public function getLimitations()
    {
        if (!extension_loaded('ssh2')){
            throw new InvalidConfigurationException(
                'The PHP ssh2 extension is required to use SFTP deployment, but it is not loaded. (You may need to install it).'
            );
        }

        return array(
            DeploymentProvider::LIMITATION_REMOTECOMMAND
        );
    }
}
