<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Deployment\DeploymentProvider;

/**
 * Class Ftp
 * @package Heyday\Component\Beam\Deployment
 */
class Ftp extends ManualChecksum implements DeploymentProvider
{
    /**
     * @var
     */
    protected $ssl;
    /**
     * @var
     */
    protected $targetPath;
    /**
     * @var
     */
    protected $writeContext;
    /**
     * @var
     */
    protected $connection;
    /**
     * @var
     */
    protected $server;
    /**
     * @var
     */
    protected $protocolString;
    /**
     * @param bool $fullmode
     * @param bool $delete
     * @param bool $ssl
     */
    public function __construct($fullmode = false, $delete = false, $ssl = false)
    {
        $this->ssl = $ssl;
        parent::__construct($fullmode, $delete);
    }
    /**
     * @param $key
     * @throws \InvalidArgumentException
     * @return mixed
     */
    protected function getConfig($key)
    {
        if (null === $this->server) {
            $this->server = $this->beam->getServer();
            if (!isset($this->server['password'])) {
                throw new \InvalidArgumentException('FTP Password is required');
            }
        }

        return $this->server[$key];
    }
    /**
     * @return resource
     * @throws \RuntimeException
     */
    protected function getConnection()
    {
        if (null === $this->connection) {
            $this->connection = ftp_connect($this->getConfig('host'));
            if (
                !@ftp_login( // Suppress warning
                    $this->connection,
                    $this->getConfig('user'),
                    $this->getConfig('password')
                )
            ) {
                throw new \RuntimeException('FTP login failed');
            }
        }

        return $this->connection;
    }
    /**
     * @{inheritDoc}
     */
    protected function writeContent($targetpath, $content)
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        ftp_fput(
            $this->getConnection(),
            $this->getTargetFilePath($targetpath),
            $handle,
            FTP_BINARY
        );
    }
    /**
     * @{inheritDoc}
     */
    protected function write($localpath, $targetpath)
    {
        ftp_put(
            $this->getConnection(),
            $this->getTargetFilePath($targetpath),
            $localpath,
            FTP_BINARY
        );
    }
    /**
     * @{inheritDoc}
     */
    protected function read($path)
    {
        $handle = fopen('php://temp', 'r+');
        if (
            @ftp_fget(
                $this->getConnection(),
                $handle,
                $this->getTargetFilePath($path),
                FTP_BINARY
            )
        ) {
            rewind($handle);

            return stream_get_contents($handle);
        }

        return false;
    }
    /**
     * @{inheritDoc}
     */
    protected function exists($path)
    {
        return file_exists($this->getProtocolPath($path));
    }
    /**
     * @{inheritDoc}
     */
    protected function mkdir($path)
    {
        mkdir($this->getProtocolPath($path), 0755, true);
    }
    /**
     * @{inheritDoc}
     */
    protected function size($path)
    {
        return filesize($this->getProtocolPath($path));
    }
    /**
     * @param $path
     * @return mixed
     */
    protected function delete($path)
    {
        ftp_delete($this->getConnection(), $this->getTargetFilePath($path));
    }
    /**
     * @param $path
     * @return string
     */
    protected function getTargetFilePath($path)
    {
        return $this->getTargetPath() . '/' . $path;
    }
    /**
     * @throws \RuntimeException
     * @return mixed
     */
    public function getTargetPath()
    {
        if (null === $this->targetPath) {
            $webroot = $this->getConfig('webroot');
            if ($webroot[0] !== '/') {
                throw new \RuntimeException('Webroot must be a absolute path when using ftp');
            }
            $this->targetPath = $webroot;
        }

        return $this->targetPath;
    }
    /**
     * @return string
     */
    protected function getProtocol()
    {
        if (null === $this->protocolString) {
            $this->protocolString = sprintf(
                'ftp%s://%s:%s@%s%s',
                $this->ssl ? 's' : '',
                $this->getConfig('user'),
                $this->getConfig('password'),
                $this->getConfig('host'),
                $this->getConfig('webroot')
            );
        }

        return $this->protocolString;
    }
    /**
     * @param $path
     * @return string
     */
    protected function getProtocolPath($path)
    {
        return sprintf(
            '%s/%s',
            $this->getProtocol(),
            $path
        );
    }
}
