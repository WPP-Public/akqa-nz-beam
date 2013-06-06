<?php

namespace Heyday\Component\Beam;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Class Compiler
 * @package Heyday\Component\Beam
 */
class Compiler
{
    /**
     * @var
     */
    protected $version;
    /**
     * @param  string $pharFile
     * @return string
     * @throws \RuntimeException
     */
    public function compile($pharFile = 'beam.phar')
    {
        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $process = new Process('git log --pretty="%H" -n1 HEAD', __DIR__);
        if ($process->run() != 0) {
            throw new \RuntimeException('Can\'t run git log. You must ensure to run compile from composer git repository clone and that git binary is available.');
        }
        $this->version = trim($process->getOutput());

        $process = new Process('git describe --tags HEAD');
        if ($process->run() == 0) {
            $this->version = trim($process->getOutput());
        }

        $phar = new \Phar($pharFile, 0, $pharFile);
        $phar->setSignatureAlgorithm(\Phar::SHA1);

        $phar->startBuffering();

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->notName('Compiler.php')
            ->in(__DIR__);

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        $vendorDir = realpath(__DIR__ . '/../../../../vendor');

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->exclude('Tests')
            ->in("$vendorDir/herzult/php-ssh/src/")
            ->in("$vendorDir/symfony/")
            ->in("$vendorDir/stecman/symfony-console-completion/src/");

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        $this->addFile($phar, new \SplFileInfo("$vendorDir/autoload.php"));
        $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/autoload_namespaces.php"));
        $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/autoload_classmap.php"));
        $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/autoload_real.php"));
        if (file_exists("$vendorDir/composer/include_paths.php")) {
            $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/include_paths.php"));
        }
        $this->addFile($phar, new \SplFileInfo("$vendorDir/composer/ClassLoader.php"));
        $this->addBin($phar);

        // Stubs
        $phar->setStub($this->getStub());

        $phar->stopBuffering();

        unset($phar);

        file_put_contents('beam.phar.version', trim($this->version));

        chmod($pharFile, 0755);

        return 'Success';
    }

    /**
     * @param $phar
     * @param $file
     */
    private function addFile($phar, $file)
    {
        $path = str_replace(realpath(__DIR__ . '/../../../../') . DIRECTORY_SEPARATOR, '', $file->getRealPath());

        $content = php_strip_whitespace($file);

        if (basename($path) !== 'SelfUpdateCommand.php') {
            $content = str_replace('~package_version~', $this->version, $content);
        }

        $phar->addFromString($path, $content);
        $phar[$path]->compress(\Phar::GZ);
    }

    /**
     * @param $phar
     */
    private function addBin($phar)
    {
        $content = file_get_contents(__DIR__ . '/../../../../bin/beam');
        $phar->addFromString('bin/beam', preg_replace('{^#!/usr/bin/env php\s*}', '', $content));
    }

    /**
     * @return string
     */
    private function getStub()
    {
        return <<<'EOF'
#!/usr/bin/env php
<?php
Phar::mapPhar('beam.phar');
require 'phar://beam.phar/bin/beam';
__HALT_COMPILER();
EOF;
    }
}
