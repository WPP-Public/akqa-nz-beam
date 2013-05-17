<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;
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
     * @param bool $fullmode
     * @param bool $ssl
     */
    public function __construct($fullmode = false, $ssl = false)
    {
        $this->ssl = $ssl;
        parent::__construct($fullmode);
    }
    protected function writeContent($targetpath, $content)
    {
        file_put_contents(
            $this->getTargetFilePath($targetpath),
            $content,
            0,
            $this->getWriteContext()
        );
    }
    protected function write($localpath, $targetpath)
    {
        file_put_contents(
            $this->getTargetFilePath($targetpath),
            file_get_contents($localpath),
            0,
            $this->getWriteContext()
        );
    }
    protected function read($path)
    {
        return file_get_contents($this->getTargetFilePath($path));
    }
    protected function exists($path)
    {
        return file_exists($this->getTargetFilePath($path));
    }
    protected function mkdir($path)
    {
        mkdir($this->getTargetFilePath($path), 0755, true);
    }
    protected function size($path)
    {
        return filesize($this->getTargetFilePath($path));
    }

    protected function getTargetFilePath($path)
    {
        return $this->getTargetPath() . '/' . $path;
    }

    protected function getWriteContext()
    {
        if (null === $this->writeContext) {
            $this->writeContext = stream_context_create(array('ftp' => array('overwrite' => true)));
        }

        return $this->writeContext;
    }

    /**
     * @throws \RuntimeException
     * @return mixed
     */
    public function getTargetPath()
    {
        if (null === $this->targetPath) {
            $server = $this->beam->getServer();
            if (!isset($server['password'])) {
                throw new \RuntimeException('Ftp requires a password');
            }
            if ($server['webroot'][0] !== '/') {
                throw new \RuntimeException('Webroot must be a absolute path when using ftp');
            }

            $this->targetPath = sprintf(
                'ftp%s://%s:%s@%s%s',
                $this->ssl ? 's' : '',
                $server['user'],
                $server['password'],
                $server['host'],
                $server['webroot']
            );
        }

        return $this->targetPath;
    }
}
