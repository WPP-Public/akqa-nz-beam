<?php

namespace Heyday\Beam\DeploymentProvider;

use Heyday\Beam\Exception\RuntimeException;
use FTP\Connection;

/**
 * Class Ftp
 * @package Heyday\Beam\DeploymentProvider
 *
 * @todo Update to support multiple `hosts' key
 */
class Ftp extends ManualChecksum
{
    protected bool $ssl;

    protected string $targetPath;

    protected ?Connection $connection = null;

    protected string $protocolString;

    /**
     * @var array
     */
    protected $listCache = [];

    /**
     * @return Connection
     * @throws RuntimeException
     */
    protected function getConnection(): Connection
    {
        if (null === $this->connection) {
            if ($this->getConfig('ssl')) {
                $connection = ftp_ssl_connect($this->getConfig('host'));
            } else {
                $connection = ftp_connect($this->getConfig('host'));
            }

            if (!$connection) {
                throw new RuntimeException("FTP connection failed\n");
            }

            $this->connection = $connection;

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

        $this->addToListCache($targetpath);
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

        $this->addToListCache($targetpath);
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
        $dir = dirname($path);

        if (!isset($this->listCache[$dir])) {
            $this->listCache[$dir] = ftp_nlist($this->getConnection(), $dir);

            if (!$this->listCache[$dir]) {
                return false;
            }

            array_walk(
                $this->listCache[$dir],
                function (&$value) {
                    $value = basename($value);
                }
            );
        }

        if (!$this->listCache[$dir]) {
            return false;
        } elseif (($key = array_search(basename($path), $this->listCache[$dir])) !== false) {
            return true;
        } elseif ($path == $dir && count($this->listCache[$dir])) {
            return true;
        } else {
            return false;
        }
    }
    /**
     * Create a directory, creating parent directories if required
     */
    protected function mkdir($path)
    {
        $connection = $this->getConnection();
        $parts = explode('/', $path);

        // Step backwards through the path to see where to start making directories
        $createDirs = [];
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
            $this->addToListCache($path);

            if (is_array($response)) {
                $code = substr($response[0], 0, 3);

                if ($code !== '257' && $code !== '550') {
                    throw new RuntimeException("Failed to mkdir '$path':\n" . implode("\n", $response));
                }
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
        if (!$this->exists($path)) {
            return;
        }

        if (!ftp_delete($this->getConnection(), $this->getTargetFilePath($path))) {
            throw new RuntimeException("File '$path' failed to delete");
        }

        $this->removeFromListCache($path);
    }
    /**
     * @param $path
     * @return string
     */
    protected function getTargetFilePath($path): string
    {
        return $this->getTargetPath() . '/' . $path;
    }
    /**
     * Add a path to the directory listing cache
     * If a newly created file or directory is not added to the cache, exists()
     * calls will return false if the parent directory already exists in the cache.
     *
     * @see exists
     * @param $path
     */
    protected function addToListCache($path)
    {
        $parent = dirname($path);

        if (!isset($this->listCache[$parent])) {
            $this->listCache[$parent] = ['.', '..'];
        }

        $this->listCache[$parent][] = basename($path);
    }
    /**
     * Remove an item from the directory listing cache
     * @param $path
     */
    protected function removeFromListCache($path)
    {
        $parent = dirname($path);

        if (isset($this->listCache[$parent]) && $this->listCache[$parent]) {
            if (($key = array_search(basename($path), $this->listCache[$parent]) !== false)) {
                unset($this->listCache[$parent][$key]);
            }
        }
    }
    /**
     * @throws RuntimeException
     */
    public function getTargetPath(): string
    {
        if (!$this->targetPath) {
            $webroot = $this->getConfig('webroot');

            $this->targetPath = rtrim($webroot, '/');
        }

        return $this->targetPath;
    }
    /**
     * Return a string representation of the target
     */
    public function getTargetAsText(): string
    {
        return $this->getConfig('user') . '@' . $this->getConfig('host') . ':' . $this->getTargetPath();
    }

    /**
     * Gets the to location for rsync for all hostnames (supports multiple hosts)
     *
     * @return array
     */
    public function getTargetPaths(): array
    {
        // @todo - support
        return [];
    }
}
