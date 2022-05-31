<?php

namespace Heyday\Beam\DeploymentProvider;

use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Utils;
use Ssh\Authentication\Password;
use Ssh\Configuration;
use Ssh\Session;
use Ssh\SshConfigFileConfiguration;

/**
 * Class Sftp
 * @package Heyday\Beam\DeploymentProvider
 *
 * @todo    Update to support multiple `hosts' key
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
     * @throws RuntimeException
     */
    protected function getSftp()
    {
        if (null === $this->sftp) {
            $server = $this->beam->getServer();

            if ($server['webroot'][0] !== '/') {
                throw new RuntimeException('Webroot must be a absolute path when using sftp');
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
     * @{inheritDoc}
     */
    protected function writeContent($targetpath, $content)
    {
        $this->getSftp()->write(
            $this->getTargetFilePath($targetpath),
            $content
        );
    }

    /**
     * @{inheritDoc}
     */
    protected function write($localpath, $targetpath)
    {
        $this->getSftp()->send(
            $localpath,
            $this->getTargetFilePath($targetpath)
        );
    }

    /**
     * @{inheritDoc}
     */
    protected function read($path)
    {
        return $this->getSftp()->read(
            $this->getTargetFilePath($path)
        );
    }

    /**
     * @{inheritDoc}
     */
    protected function exists($path)
    {
        return $this->getSftp()->exists(
            $this->getTargetFilePath($path)
        );
    }

    /**
     * @{inheritDoc}
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
     * @{inheritDoc}
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
     * @return mixed
     */
    protected function delete($path)
    {
        $this->getSftp()->unlink(
            $this->getTargetFilePath($path)
        );
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

    /**
     * Return a string representation of the target
     * @return string
     */
    public function getTargetAsText()
    {
        return $this->getConfig('user') . '@' . $this->getConfig('host') . ':' . $this->getTargetPath();
    }

    /**
     * @throws \Heyday\Beam\Exception\InvalidEnvironmentException
     * @return array
     */
    public function getLimitations()
    {
        Utils::checkExtension(
            'ssh2',
            "The PHP '%s' extension is required to use SFTP deployment, but it is not loaded. (You may need to install it)."
        );

        return [
            DeploymentProvider::LIMITATION_REMOTECOMMAND
        ];
    }

    /**
     * Gets the to location for rsync for all hostnames (supports multiple hosts)
     *
     * @return array
     */
    public function getTargetPaths()
    {
        // @todo - support
        return [];
    }
}
