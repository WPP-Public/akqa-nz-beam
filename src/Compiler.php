<?php

namespace Heyday\Beam;

use Heyday\Beam\Exception\InvalidArgumentException;
use Heyday\Beam\Exception\RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Phar;
use SplFileInfo;

/**
 * Class Compiler
 * @package Heyday\Beam
 */
class Compiler
{
    private string $pharFile;

    private string $pharPath;

    private string $rootDir;

    private string $vendorDir;

    private string $version;

    private int $chmod;

    private int $compression;

    private array $classMap = [];

    /**
     * @param int $chmod
     * @param     $compression
     */
    public function __construct($pharFile = 'beam.phar', $chmod = 0755, $compression = Phar::GZ)
    {
        $this->pharFile = $pharFile;
        $this->rootDir = realpath(__DIR__ . '/..');
        $this->pharPath = sprintf(
            '%s/%s',
            $this->rootDir,
            $this->pharFile
        );
        $this->vendorDir = $this->rootDir . '/vendor';
        $this->chmod = $chmod;
        if (!in_array($compression, [Phar::GZ, Phar::NONE])) {
            throw new InvalidArgumentException("Unknown compression type");
        }
        if (!Phar::canCompress($compression)) {
            throw new InvalidArgumentException("Can't use requested compression type");
        }
        $this->compression = $compression;
    }

    /**
     * @throws RuntimeException
     */
    public function compile(): string
    {
        if (!file_exists($this->rootDir . '/composer.lock')) {
            throw new RuntimeException("Composer dependencies not installed");
        }

        echo $this->runCommand(
            './vendor/bin/phpunit', "Unit tests failed, check that phpunit is installed", true)->getOutput();

        $process = $this->runCommand(
            'git log --pretty="%H" -n1 HEAD',
            "Can't run git log. You must ensure to run compile from composer git repository clone and that git binary is available."
        );

        $this->version = trim($process->getOutput());

        try {
            $this->version = trim($this->runCommand('git describe --tags HEAD', 'Failed')->getOutput());
        } catch (RuntimeException $e) {
        }

        // Remove previously compiled file
        if (file_exists($this->pharFile)) {
            unlink($this->pharFile);
        }

        $phar = new Phar($this->pharPath, 0, $this->pharFile);
        $phar->setSignatureAlgorithm(Phar::SHA1);
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

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->exclude('Tests')
            ->in("$this->vendorDir/herzult/php-ssh/src/")
            ->in("$this->vendorDir/symfony/console/")
            ->in("$this->vendorDir/symfony/process/")
            ->in("$this->vendorDir/symfony/options-resolver/")
            ->in("$this->vendorDir/symfony/config/")
            ->in("$this->vendorDir/stecman/symfony-console-completion/src/")
            ->in("$this->vendorDir/seld/jsonlint/src/");

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        // Add autoloader
        $this->addAutoloader($phar);

        // Add runner
        $phar->addFromString(
            'bin/beam',
            preg_replace(
                '{^#!/usr/bin/env php\s*}',
                '',
                php_strip_whitespace($this->rootDir . '/bin/beam')
            )
        );

        $phar->compressFiles($this->compression);

        // Stubs
        $phar->setStub($this->getStub());
        $phar->stopBuffering();

        unset($phar);

        file_put_contents('beam.phar.version', trim($this->version));

        chmod($this->pharFile, $this->chmod);

        return $this->pharFile;
    }

    /**
     * Copied from Symfony
     * @param string $content
     * @return array
     */
    private function getClassesFromContent(string $content): array
    {
        $tokens   = token_get_all($content);
        $T_TRAIT  = version_compare(PHP_VERSION, '5.4', '<') ? -1 : T_TRAIT;

        $classes = [];

        $namespace = '';
        for ($i = 0, $max = count($tokens); $i < $max; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                continue;
            }

            $class = '';

            switch ($token[0]) {
                case T_NAMESPACE:
                    $namespace = '';
                    // If there is a namespace, extract it
                    while (($t = $tokens[++$i]) && is_array($t)) {
                        if (in_array($t[0], [T_STRING, T_NS_SEPARATOR])) {
                            $namespace .= $t[1];
                        }
                    }
                    $namespace .= '\\';
                    break;
                case T_CLASS:
                case T_INTERFACE:
                case $T_TRAIT:
                    // Find the classname
                    while (($t = $tokens[++$i]) && is_array($t)) {
                        if (T_STRING === $t[0]) {
                            $class .= $t[1];
                        } elseif ($class !== '' && T_WHITESPACE == $t[0]) {
                            break;
                        }
                    }

                    $classes[] = ltrim($namespace . $class, '\\');
                    break;
                default:
                    break;
            }
        }

        return $classes;
    }

    /**
     * @param Phar $phar
     */
    private function addAutoloader(Phar $phar)
    {
        $map = 'array(';
        foreach ($this->classMap as $class => $path) {
            $map .= "'$class' => \$dir.'/$path',";
        }
        $map .= ')';

        // phpcs:disable
        $template = file_get_contents($this->rootDir . '/templates/autoloader.php');
        $content = str_replace('$map', $map, $template);

        $phar->addFromString('vendor/autoload.php', $content);
        // phpcs:enable

        $this->addFile($phar, new SplFileInfo($this->vendorDir . "/composer/ClassLoader.php"));
    }

    /**
     * @param $phar
     * @param $file
     */
    private function addFile(Phar $phar, SplFileInfo $file)
    {
        $path = str_replace($this->rootDir . '/', '', $file->getRealPath());

        $content = php_strip_whitespace($file);

        if (basename($path) !== 'SelfUpdateCommand.php') {
            $content = str_replace('~package_version~', $this->version, $content);
        }

        $phar->addFromString($path, $content);

        foreach ($this->getClassesFromContent($content) as $class) {
            $this->classMap[$class] = $path;
        }
    }


    private function getStub(): string
    {
        return file_get_contents($this->rootDir . '/templates/stub.php');
    }

    /**
     * @param      $cmd
     * @param      $failedMessage
     * @param bool $outputError
     * @throws Exception\RuntimeException
     * @return Process
     */
    private function runCommand($cmd, $failedMessage, $outputError = false)
    {
        $process = Process::fromShellCommandline($cmd, $this->rootDir);

        if ($process->run() !== 0) {
            if ($outputError) {
                echo $process->getErrorOutput();
            }
            throw new RuntimeException($failedMessage);
        }
        return $process;
    }
}
