<?php

namespace Heyday\Component\Beam\DeploymentProvider;

use Heyday\Component\Beam\DeploymentProvider\DeploymentProvider;
use Heyday\Component\Beam\Exception\RuntimeException;

/**
 * Class Ftp
 * @package Heyday\Component\Beam\DeploymentProvider
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
    protected $protocolString;
    /**
     * @var array
     */
    /**
     * @return resource
     * @throws RuntimeException
     */
    protected function getConnection()
    {
        if (null === $this->connection) {
            if ($this->getConfig('ssl')) {
                $this->connection = ftp_ssl_connect($this->getConfig('host'));
            } else {
                $this->connection = ftp_connect($this->getConfig('host'));
            }
            if (!$this->connection) {
                throw new RuntimeException("FTP connection failed\n");
            }

            if (
                !ftp_login(
                    $this->connection,
                    $this->getConfig('user'),
                    $this->getConfig('password')
                )
            ) {
                throw new RuntimeException("FTP login failed");
            }

            ftp_pasv($this->connection, $this->getConfig('passive'));
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
            ftp_fget(
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
        $path = $this->getTargetFilePath($path);
        $response = ftp_raw($this->getConnection(), "MLST $path");
        return substr($response[0], 0, 3) === '250';
    }
    /**
     * Create a directory, creating parent directories if required
     */
    protected function mkdir($path)
    {
        $connection = $this->getConnection();
        $parts = explode('/', $path);

        // Step backwards through the path to see where to start making directories
        $createDirs = array();
        for ($i = count($parts); $i >= 0; $i--) {
            $exists = $this->exists(
                $path = implode('/', array_slice($parts, 0, $i))
            );

            if ($exists) {
                break;
            } else {
                array_unshift($createDirs, $this->getTargetFilePath($path));
            }
        }

        // Create each directory that didn't exist
        foreach ($createDirs as $path) {
            $response = ftp_raw($connection, "MKD $path");
            if (substr($response[0], 0, 3) !== '257') {
                throw new RuntimeException("Failed to mkdir '$path':\n" .implode("\n", $response));
            }
        }
    }
    /**
     * @{inheritDoc}
     */
    protected function size($path)
    {
        $size = ftp_size($this->getConnection(), $this->getTargetFilePath($path));

        if ($size == -1) {
            throw new RuntimeException("Failed to get size for '$path'");
        }

        return $size;
    }
    /**
     * @param $path
     * @return void
     * @throws RuntimeException
     */
    protected function delete($path)
    {
        if (!ftp_delete($this->getConnection(), $this->getTargetFilePath($path))) {
            throw new RuntimeException("File '$path' failed to delete");
        }
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
     * @throws RuntimeException
     * @return mixed
     */
    public function getTargetPath()
    {
        if (null === $this->targetPath) {
            $webroot = $this->getConfig('webroot');
            if ($webroot[0] !== '/') {
                throw new RuntimeException('Webroot must be a absolute path when using ftp');
            }
            $this->targetPath = rtrim($webroot, '/');
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
}
