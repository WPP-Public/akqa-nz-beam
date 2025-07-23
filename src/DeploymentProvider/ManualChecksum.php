<?php

namespace Heyday\Beam\DeploymentProvider;

use Heyday\Beam\Exception\InvalidConfigurationException;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Utils;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Class ManualChecksum
 * @package Heyday\Beam\DeploymentProvider
 */
abstract class ManualChecksum extends Deployment implements DeploymentProvider
{
    /**
     * @var bool
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
     * @var bool
     */
    protected $force;

    /**
     * @param bool $fullmode
     * @param bool $delete
     */
    public function __construct($fullmode = false, $delete = false, $force = false)
    {
        $this->fullmode = $fullmode;
        $this->delete = $delete;
        $this->force = $force;
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
        }

        return $this->server[$key];
    }
    /**
     * @param callable         $output
     * @param bool             $dryrun
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
            $result = [];

            if ($this->fullmode) {
                foreach ($localchecksums as $targetpath => $checksum) {
                    $path = $dir . '/' . $targetpath;

                    if ($this->force) {
                        // skip checking the remote server completely and rely
                        // on sending the file
                        $result[] = [
                            'update'        => 'sent',
                            'filename'      => $targetpath,
                            'localfilename' => $path,
                            'filetype'      => 'file',
                            'reason'        => ['forced']
                        ];
                    } else {
                        if ($this->exists($targetpath)) {
                            if (
                                $targetchecksums &&
                                isset($targetchecksums[$targetpath]) &&
                                $targetchecksums[$targetpath] !== $localchecksums[$targetpath]
                            ) {
                                $result[] = [
                                    'update'        => 'sent',
                                    'filename'      => $targetpath,
                                    'localfilename' => $path,
                                    'filetype'      => 'file',
                                    'reason'        => ['checksum']
                                ];
                            } elseif ($this->size($targetpath) !== filesize($path)) {
                                $result[] = [
                                    'update'        => 'sent',
                                    'filename'      => $targetpath,
                                    'localfilename' => $path,
                                    'filetype'      => 'file',
                                    'reason'        => ['size']
                                ];
                            }
                        } else {
                            $result[] = [
                                'update'        => 'created',
                                'filename'      => $targetpath,
                                'localfilename' => $path,
                                'filetype'      => 'file',
                                'reason'        => ['missing']
                            ];
                        }
                    }
                }
            } else {
                if (!$targetchecksums) {
                    throw new RuntimeException('No checksums file found on target. Use --full mode without checksums.');
                }

                foreach (array_diff_assoc($localchecksums, $targetchecksums) as $path => $checksum) {
                    if (isset($targetchecksums[$path])) {
                        $result[] = [
                            'update'        => 'sent',
                            'filename'      => $path,
                            'localfilename' => $dir . '/' . $path,
                            'filetype'      => 'file',
                            'reason'        => ['checksum']
                        ];
                    } else {
                        $result[] = [
                            'update'        => 'created',
                            'filename'      => $path,
                            'localfilename' => $dir . '/' . $path,
                            'filetype'      => 'file',
                            'reason'        => ['missing']
                        ];
                    }
                }
            }

            if ($targetchecksums && $this->delete) {
                foreach (array_diff_key($targetchecksums, $localchecksums) as $path => $checksum) {
                    $result[] = [
                        'update'        => 'deleted',
                        'filename'      => $path,
                        'localfilename' => $dir . '/' . $path,
                        'filetype'      => 'file',
                        'reason'        => ['missing']
                    ];
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
                'checksums.json.gz',
                Utils::checksumsToGz(
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
     * Prompt for password if not set in selected server config
     * Implements DeploymentProvider::configure for descendant classes
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Heyday\Beam\Exception\InvalidArgumentException
     */
    public function configure(InputInterface $input, OutputInterface $output)
    {
        $server = $this->beam->getServer();

        if (empty($server['password'])) {
            $formatterHelper = new FormatterHelper();
            $questionHelper = new QuestionHelper();
            $serverName = $this->beam->getOption('target');

            $question = new Question(
                $formatterHelper->formatSection(
                    'Prompt',
                    Utils::getQuestion("Enter password for $serverName:"),
                    'comment'
                ),
                false
            );

            $question->setHidden(true);

            $this->server['password'] = $questionHelper->ask($input, $output, $question);
        }
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
        return [
            DeploymentProvider::LIMITATION_REMOTECOMMAND
        ];
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
            $files = [];
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
        if ($this->exists('checksums.json.gz')) {
            $targetchecksums = Utils::checksumsFromGz($this->read('checksums.json.gz'));

            if (isset($targetchecksums)) {
                return Utils::getFilteredChecksums(
                    $this->beam->getConfig('exclude'),
                    $targetchecksums
                );
            }
        }
    }
}
