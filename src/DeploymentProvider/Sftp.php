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
    protected ?\Ssh\Sftp $sftp = null;

    protected ?string $targetPath = null;

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

                $auth = $configuration->getAuthentication(
                    null,
                    $server['user']
                );

                $session = new Session($configuration, $auth);
            }

            $this->sftp = $session->getSftp();
        }

        return $this->sftp;
    }

    /**
     * @{inheritDoc}
     */
    protected function writeContent(string $targetpath, $content)
    {
        $this->getSftp()->write(
            $this->getTargetFilePath($targetpath),
            $content
        );
    }

    /**
     * @{inheritDoc}
     */
    protected function write(string $localpath, string $targetpath)
    {
        $this->getSftp()->send(
            $localpath,
            $this->getTargetFilePath($targetpath)
        );
    }

    /**
     * @{inheritDoc}
     */
    protected function read(string $path)
    {
        return $this->getSftp()->read(
            $this->getTargetFilePath($path)
        );
    }

    /**
     * @{inheritDoc}
     */
    protected function exists(string $path)
    {
        return $this->getSftp()->exists(
            $this->getTargetFilePath($path)
        );
    }

    /**
     * @{inheritDoc}
     */
    protected function mkdir(string $path)
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
    protected function size(string $path)
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
    protected function delete(string $path)
    {
        $this->getSftp()->unlink(
            $this->getTargetFilePath($path)
        );
    }

    /**
     * @param string $path
     */
    protected function getTargetFilePath(string $path): string
    {
        return $this->getTargetPath() . '/' . $path;
    }

    /**
     * @return string
     */
    public function getTargetPath(): string
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
    public function getTargetAsText(): string
    {
        return $this->getConfig('user') . '@' . $this->getConfig('host') . ':' . $this->getTargetPath();
    }

    /**
     * @throws \Heyday\Beam\Exception\InvalidEnvironmentException
     * @return array
     */
    public function getLimitations(): array
    {
        Utils::checkExtension(
            'ssh2',
            "The PHP '%s' extension is required to use SFTP deployment, but it is not loaded."
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
    public function getTargetPaths(): array
    {
        return [];
    }
}
