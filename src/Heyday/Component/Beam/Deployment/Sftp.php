<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Deployment\DeploymentProvider;
use Ssh\Authentication\Password;
use Ssh\Configuration;
use Ssh\Session;
use Ssh\SshConfigFileConfiguration;

/**
 * Class Sftp
 * @package Heyday\Component\Beam\Deployment
 */
class Sftp extends ManualChecksum implements DeploymentProvider
{
    /**
     * @var
     */
    protected $sftp;
    /**
     * @var
     */
    protected $targetPath;
    /**
     * @return \Ssh\Sftp
     * @throws \RuntimeException
     */
    protected function getSftp()
    {
        if (null === $this->sftp) {
            $server = $this->beam->getServer();

            if ($server['webroot'][0] !== '/') {
                throw new \RuntimeException('Webroot must be a absolute path when using sftp');
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
     * @param $targetpath
     * @param $content
     */
    protected function writeContent($targetpath, $content)
    {
        $this->getSftp()->write(
            $this->getTargetFilePath($targetpath),
            $content
        );
    }
    /**
     * @param $localpath
     * @param $targetpath
     */
    protected function write($localpath, $targetpath)
    {
        $this->getSftp()->send(
            $localpath,
            $this->getTargetFilePath($targetpath)
        );
    }
    /**
     * @param $path
     * @return string
     */
    protected function read($path)
    {
        return $this->getSftp()->read(
            $this->getTargetFilePath($path)
        );
    }
    /**
     * @param $path
     * @return bool
     */
    protected function exists($path)
    {
        return $this->getSftp()->exists(
            $this->getTargetFilePath($path)
        );
    }
    /**
     * @param $path
     */
    protected function mkdir($path)
    {
        $this->getSftp()->mkdir(
            $this->getTargetFilePath($path),
            0755,
            true
        );
    }
    /**
     * @param $path
     * @return mixed
     */
    protected function size($path)
    {
        $stat = $this->getSftp()->lstat(
            $this->getTargetFilePath($path)
        );
        return $stat['size'];
    }
    /**
     * @param $path
     * @return mixed|string
     */
    protected function getTargetFilePath($path)
    {
        return $this->getTargetPath() . '/' . $path;
    }
    /**
     * @return mixed
     */
    public function getTargetPath()
    {
        if (null === $this->targetPath) {
            $server = $this->beam->getServer();
            $this->targetPath = $server['webroot'];
        }

        return $this->targetPath;
    }
}
