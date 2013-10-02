<?php

namespace Heyday\Component\Beam\DeploymentProvider;

use Heyday\Component\Beam\Utils;
use Heyday\Component\Beam\Exception\InvalidConfigurationException;
use Heyday\Component\Beam\Exception\RuntimeException;

/**
 * Class ManualChecksum
 * @package Heyday\Component\Beam\DeploymentProvider
 */
abstract class ManualChecksum extends Deployment
{
    /**
     * @var
     */
    protected $fullmode;
    /**
     * @var bool
     */
    protected $delete;
    /**
     * @var
     */
    protected $server;
    /**
     * @param bool $fullmode
     * @param bool $delete
     */
    public function __construct($fullmode = false, $delete = false)
    {
        $this->fullmode = $fullmode;
        $this->delete = $delete;
    }
    /**
     * @param $key
     * @throws InvalidConfigurationException
     * @return mixed
     */
    protected function getConfig($key)
    {
        if (null === $this->server) {
            $this->server = $this->beam->getServer();
            if (!isset($this->server['password'])) {
                throw new InvalidConfigurationException('(S)FTP Password is required');
            }
        }

        return $this->server[$key];
    }
    /**
     * @param  callable                                                   $output
     * @param  bool                                                       $dryrun
     * @param DeploymentResult $deploymentResult
     * @return DeploymentResult
     * @throws RuntimeException
     */
    public function up(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        $dir = $this->beam->getLocalPath();

        $files = $this->getAllowedFiles($dir);
        $localchecksums = Utils::checksumsFromFiles($files, $dir);
        $targetchecksums = $this->getTargetChecksums();

        if (null === $deploymentResult) {

            $result = array();

            if ($this->fullmode) {
                foreach ($localchecksums as $targetpath => $checksum) {
                    $path = $dir . '/' . $targetpath;
                    if ($this->exists($targetpath)) {
                        if (
                            $targetchecksums &&
                            isset($targetchecksums[$targetpath]) &&
                            $targetchecksums[$targetpath] !== $localchecksums[$targetpath]
                        ) {
                            $result[] = array(
                                'update'        => 'sent',
                                'filename'      => $targetpath,
                                'localfilename' => $path,
                                'filetype'      => 'file',
                                'reason'        => array('checksum')
                            );
                        } elseif ($this->size($targetpath) !== filesize($path)) {
                            $result[] = array(
                                'update'        => 'sent',
                                'filename'      => $targetpath,
                                'localfilename' => $path,
                                'filetype'      => 'file',
                                'reason'        => array('size')
                            );
                        }
                    } else {
                        $result[] = array(
                            'update'        => 'created',
                            'filename'      => $targetpath,
                            'localfilename' => $path,
                            'filetype'      => 'file',
                            'reason'        => array('missing')
                        );
                    }
                }
            } else {

                if (!$targetchecksums) {
                    throw new RuntimeException('No checksums file found on target. Use --full mode to work without checksums.');
                }

                foreach (array_diff_assoc($localchecksums, $targetchecksums) as $path => $checksum) {
                    if (isset($targetchecksums[$path])) {
                        $result[] = array(
                            'update'        => 'sent',
                            'filename'      => $path,
                            'localfilename' => $dir . '/' . $path,
                            'filetype'      => 'file',
                            'reason'        => array('checksum')
                        );
                    } else {
                        $result[] = array(
                            'update'        => 'created',
                            'filename'      => $path,
                            'localfilename' => $dir . '/' . $path,
                            'filetype'      => 'file',
                            'reason'        => array('missing')
                        );
                    }
                }
            }

            if ($targetchecksums && $this->delete) {
                foreach (array_diff_key($targetchecksums, $localchecksums) as $path => $checksum) {
                    $result[] = array(
                        'update'        => 'deleted',
                        'filename'      => $path,
                        'localfilename' => $dir . '/' . $path,
                        'filetype'      => 'file',
                        'reason'        => array('missing')
                    );
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
                if ($change['update'] === 'deleted') {
                    $this->delete($change['filename']);
                } else {
                    if (!$this->exists($dir)) {
                        $this->mkdir($dir);
                    }
                    $this->write(
                        $change['localfilename'],
                        $change['filename']
                    );
                }
            }
            // Save the checksums to the server
            $this->writeContent(
                'checksums.json.bz2',
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
     * @throws RuntimeException
     * @return mixed
     */
    public function down(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        // TODO: Implement down() method.
        throw new RuntimeException('Not implemented');
    }
    /**
     * @return array
     */
    public function getLimitations()
    {
        return array(
            DeploymentProvider::LIMITATION_REMOTECOMMAND
        );
    }
    /**
     * @param $targetpath
     * @param $content
     * @return mixed
     */
    abstract protected function writeContent($targetpath, $content);
    /**
     * @param $localpath
     * @param $targetpath
     * @return mixed
     */
    abstract protected function write($localpath, $targetpath);
    /**
     * @param $path
     * @return mixed
     */
    abstract protected function read($path);
    /**
     * @param $path
     * @return mixed
     */
    abstract protected function exists($path);
    /**
     * @param $path
     * @return mixed
     */
    abstract protected function mkdir($path);
    /**
     * @param $path
     * @return mixed
     */
    abstract protected function size($path);
    /**
     * @param $path
     * @return mixed
     */
    abstract protected function delete($path);
    /**
     * @param $dir
     * @return array
     */
    protected function getAllowedFiles($dir)
    {
        if ($this->beam->hasPath()) {
            $files = array();
            foreach ($this->beam->getOption('path') as $path) {
                $matchedFiles = Utils::getAllowedFilesFromDirectory(
                    $this->beam->getConfig('exclude'),
                    $dir . '/' . $path
                );

                $files = array_merge($files, $matchedFiles);
            }

        } else {

            $files = Utils::getAllowedFilesFromDirectory(
                $this->beam->getConfig('exclude'),
                $dir
            );
        }

        return $files;
    }
    /**
     * @return array
     */
    protected function getTargetChecksums()
    {
        if ($this->exists('checksums.json.bz2')) {
            $targetchecksums = Utils::checksumsFromBz2($this->read('checksums.json.bz2'));

        if (isset($targetchecksums)) {
                return Utils::getFilteredChecksums(
                $this->beam->getConfig('exclude'),
                $targetchecksums
            );
        }
    }
    }
}
