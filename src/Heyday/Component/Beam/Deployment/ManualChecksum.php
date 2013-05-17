<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Utils;

abstract class ManualChecksum extends Deployment
{
    /**
     * @var
     */
    protected $fullmode;
    /**
     * @param bool $fullmode
     */
    public function __construct($fullmode = false)
    {
        $this->fullmode = $fullmode;
    }
    /**
     * @param  callable                                           $output
     * @param  bool                                               $dryrun
     * @param  \Heyday\Component\Beam\Deployment\DeploymentResult $deploymentResult
     * @return \Heyday\Component\Beam\Deployment\DeploymentResult
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

        if (function_exists('bzdecompress') && $this->exists('checksums.json.bz2')) {
            $targetchecksums = Utils::checksumsFromBz2($this->read('checksums.json.bz2'));
        } elseif (function_exists('gzinflate') && $this->exists('checksums.json.gz')) {
            $targetchecksums = Utils::checksumsFromGz($this->read('checksums.json.gz'));
        } elseif ($this->exists('checksums.json')) {
            $targetchecksums = Utils::checksumsFromString($this->read('checksums.json'));
        }

        $targetchecksums = Utils::getFilteredChecksums(
            $this->beam->getConfig('exclude'),
            $targetchecksums
        );

        if (null === $deploymentResult) {

            $result = array();

            foreach ($files as $file) {
                $path = $file->getPathname();
                $targetpath = Utils::getRelativePath($dir, $path);

                if ($this->fullmode) {

                    if ($this->exists($targetpath)) {
                        if (isset($targetchecksums[$targetpath]) && $targetchecksums[$targetpath] !== $localchecksums[$targetpath]) {
                            $result[] = array(
                                'update'        => 'sent',
                                'filename'      => $targetpath,
                                'localfilename' => $path,
                                'filetype'      => 'file',
                                'reason'        => array('checksum')
                            );
                        } else {
                            if ($this->size($targetpath) !== filesize($path)) {
                                $result[] = array(
                                    'update'        => 'sent',
                                    'filename'      => $targetpath,
                                    'localfilename' => $path,
                                    'filetype'      => 'file',
                                    'reason'        => array('size')
                                );
                            }
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

                } else {

                    if (isset($targetchecksums[$targetpath])) {
                        if ($targetchecksums[$targetpath] !== $localchecksums[$targetpath]) {
                            $result[] = array(
                                'update'        => 'sent',
                                'filename'      => $targetpath,
                                'localfilename' => $path,
                                'filetype'      => 'file',
                                'reason'        => array('checksum')
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
            }

            $deploymentResult = new DeploymentResult($result);
        }

        if (!$dryrun) {
            foreach ($deploymentResult as $change) {
                if (is_callable($output)) {
                    $output();
                }
                $dir = dirname($change['filename']);
                if (!$this->exists($dir)) {
                    $this->mkdir($dir);
                }
                $this->write(
                    $change['localfilename'],
                    $change['filename']
                );
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
     * @throws \RuntimeException
     * @return mixed
     */
    public function down(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null)
    {
        // TODO: Implement down() method.
        throw new \RuntimeException('Not implemented');
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

    abstract protected function writeContent($targetpath, $content);
    abstract protected function write($localpath, $targetpath);
    abstract protected function read($path);
    abstract protected function exists($path);
    abstract protected function mkdir($path);
    abstract protected function size($path);
}
