<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Deployment\DeploymentProvider;
use Heyday\Component\Beam\Utils;
use Heyday\Component\Beam\Deployment\DeploymentResult;

class Ftp extends Deployment implements DeploymentProvider
{
    /**
     * @var
     */
    protected $fullmode;
    /**
     * @var
     */
    protected $ssl;
    /**
     * @param bool $fullmode
     * @param bool $ssl
     */
    public function __construct($fullmode = false, $ssl = false)
    {
        $this->fullmode = $fullmode;
        $this->ssl = $ssl;
    }
    /**
     * @param callable         $output
     * @param bool             $dryrun
     * @param DeploymentResult $deploymentResult
     * @return DeploymentResult|mixed
     */
    public function up(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        $dir = $this->beam->getLocalPath();

        $files = Utils::getAllowedFilesFromDirectory(
            $this->beam->getConfig('exclude'),
            $dir . ($this->beam->hasPath() ? '/' . $this->beam->getOption('path') : '')
        );

        $localchecksums = Utils::checksumsFromFiles($files, $dir);

        $targetchecksums = array();

        $targetchecksumfile = $this->getTargetFullPath('checksums.json');

        if (function_exists('bzdecompress') && file_exists($targetchecksumfile . '.bz2')) {
            $targetchecksums = Utils::checksumsFromBz2(file_get_contents($targetchecksumfile . '.bz2'));
        } elseif (function_exists('gzinflate') && file_exists($targetchecksumfile . '.gz')) {
            $targetchecksums = Utils::checksumsFromGz(file_get_contents($targetchecksumfile . '.gz'));
        } elseif (file_exists($targetchecksumfile)) {
            $targetchecksums = Utils::checksumsFromString(file_get_contents($targetchecksumfile));
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

                    if (file_exists($targetfile)) {
                        if (isset($targetchecksums[$relativefilename]) && $targetchecksums[$relativefilename] !== $localchecksums[$relativefilename]) {
                            $result[] = array(
                                'update' => 'sent',
                                'filename' => $relativefilename,
                                'localfilename' => $path,
                                'filetype' => 'file',
                                'reason' => array('checksum')
                            );
                        } else {
                            if (filesize($targetfile) != filesize($path)) {
                                $result[] = array(
                                    'update' => 'sent',
                                    'filename' => $relativefilename,
                                    'localfilename' => $path,
                                    'filetype' => 'file',
                                    'reason' => array('size')
                                );
                            }
                        }
                    } else {
                        $result[] = array(
                            'update' => 'created',
                            'filename' => $relativefilename,
                            'localfilename' => $path,
                            'filetype' => 'file',
                            'reason' => array('missing')
                        );
                    }

                } else {

                    if (isset($targetchecksums[$relativefilename])) {
                        if ($targetchecksums[$relativefilename] !== $localchecksums[$relativefilename]) {
                            $result[] = array(
                                'update' => 'sent',
                                'filename' => $relativefilename,
                                'localfilename' => $path,
                                'filetype' => 'file',
                                'reason' => array('checksum')
                            );
                        }
                    } else {
                        $result[] = array(
                            'update' => 'created',
                            'filename' => $relativefilename,
                            'localfilename' => $path,
                            'filetype' => 'file',
                            'reason' => array('missing')
                        );
                    }

                }
            }

            $deploymentResult = new DeploymentResult($result);

        }

        if (!$dryrun && !$this->beam->getOption('dry-run')) {
            $writecontext = stream_context_create(array('ftp' => array('overwrite' => true)));
            foreach ($deploymentResult as $change) {
                if (is_callable($output)) {
                    $output();
                }
                $dir = $this->getTargetFullPath(dirname($change['filename']));
                if (!file_exists($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents(
                    $this->getTargetFullPath($change['filename']),
                    file_get_contents($change['localfilename']),
                    0,
                    $writecontext
                );
            }
            // Save the checksums to the server
            file_put_contents(
                $this->getTargetFullPath('checksums.json.bz2'),
                Utils::checksumsToBz2(
                    $this->beam->hasPath() ? array_merge(
                        $targetchecksums,
                        $localchecksums
                    ) : $localchecksums
                ),
                0,
                $writecontext
            );
        }

        return $deploymentResult;
    }
    /**
     * @param  callable        $output
     * @param  bool            $dryrun
     * @param DeploymentResult $deploymentResult
     * @throws \RuntimeException
     * @return mixed
     */
    public function down(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        throw new \RuntimeException('Not implemented');
    }
    /**
     * @param $path
     * @return string
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
    protected function getTargetFullPath($path)
    {
        return $this->getTargetPath() . '/' . $path;
    }
    /**
     * @throws \RuntimeException
     * @return mixed
     */
    public function getTargetPath()
    {
        $server = $this->beam->getServer();
        if (!isset($server['password'])) {
            throw new \RuntimeException('Ftp requires a password');
        }
        if ($server['webroot'][0] !== '/') {
            throw new \RuntimeException('Webroot must be a absolute path when using ftp');
        }
        return sprintf(
            'ftp%s://%s:%s@%s%s',
            $this->ssl ? 's' : '',
            $server['user'],
            $server['password'],
            $server['host'],
            $server['webroot']
        );
    }
}