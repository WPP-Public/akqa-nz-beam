<?php

namespace Heyday\Beam;

use Heyday\Beam\Exception\InvalidEnvironmentException;
use Symfony\Component\Process\Process;

/**
 * Class Utils
 * @package Heyday\Beam
 */
class Utils
{
    /**
     * @param  \Closure $condition
     * @param  string   $dir
     * @return array
     */
    public static function getFilesFromDirectory(\Closure $condition, string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if (
                !in_array($file->getBasename(), ['.', '..']) && ($file->isFile() || $file->isLink()) && $condition(
                    $file
                )
            ) {
                $files[] = $file;
            }
        }

        return $files;
    }
    /**
     * @param $excludes
     * @param string $dir
     * @return array
     */
    public static function getAllowedFilesFromDirectory(array $excludes, string $dir): array
    {
        return Utils::getFilesFromDirectory(
            function ($file) use ($excludes, $dir) {
                return !Utils::isFileExcluded(
                    $excludes,
                    Utils::getRelativePath(
                        $dir,
                        $file->getPathname()
                    )
                );
            },
            $dir
        );
    }
    /**
     * @param $excludes
     * @param $checksums
     * @return array
     */
    public static function getFilteredChecksums(array $excludes, array $checksums)
    {
        foreach (array_keys($checksums) as $path) {
            if (Utils::isFileExcluded($excludes, $path)) {
                unset($checksums[$path]);
            }
        }

        return $checksums;
    }
    /**
     * # if the pattern starts with a / then it is matched against the start of the filename, otherwise it is matched
     *   against the end of the filename. Thus "/foo" would match a file called "foo" at the base of the tree.
     *   On the other hand, "foo" would match any file called "foo" anywhere in the tree because the algorithm is
     *   applied recursively from top down; it behaves as if each path component gets a turn at being the
     *   end of the file name.
     *
     * # if the pattern ends with a / then it will only match a directory, not a file, link or device.
     *
     * # if the pattern contains a wildcard character from the set *?[ then expression matching is applied using the
     *   shell filename matching rules. Otherwise a simple string match is used.
     *
     * # if the pattern includes a double asterisk "**" then all wildcards in the pattern will match slashes, otherwise
     *   they will stop at slashes.
     *
     * # if the pattern contains a / (not counting a trailing /) then it is matched against the full filename, including
     *   any leading directory. If the pattern doesn't contain a / then it is matched only against the final component
     *   of the filename. Again, remember that the algorithm is applied recursively so "full filename" can actually be
     *   any portion of a path.
     *
     * @param $patterns
     * @param $path string A relative path
     * @return bool
     */
    public static function isFileExcluded(array $patterns, $path)
    {
        $path = '/' . ltrim($path, '/');
        foreach ($patterns as $pattern) {
            if (substr($pattern, -1) === '/') {
                $pattern = $pattern . '*';
                if ($pattern[0] !== '/') {
                    $pattern = '*' . $pattern;
                }
            } elseif ($pattern[0] !== '/') {
                $pattern = [
                    '*/' . $pattern,
                    '*/' . $pattern . '/*'
                ];
            }

            foreach ((array) $pattern as $subpattern) {
                if (fnmatch($subpattern, $path)) {
                    return true;
                }
            }
        }

        return false;
    }
    /**
     * @param $dir
     * @param $path
     * @return mixed
     */
    public static function getRelativePath($dir, $path)
    {
        return str_replace($dir . '/', '', $path);
    }
    /**
     * @param  array $files
     * @param        $dir
     * @return array
     */
    public static function checksumsFromFiles(array $files, $dir)
    {
        $checksums = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            $checksums[Utils::getRelativePath($dir, $path)] = md5_file($path);
        }

        return $checksums;
    }
    /**
     * @param  array  $checksums
     * @throws InvalidEnvironmentException
     * @return string
     */
    public static function checksumsToGz(array $checksums)
    {
        self::checkExtension('zlib');

        return gzencode(json_encode($checksums), 9);
    }
    /**
     * @param $data
     * @return mixed
     */
    public static function checksumsFromGz($data)
    {
        self::checkExtension('zlib');

        return json_decode(gzinflate(substr($data, 10, -8)), true);
    }
    /**
     * Recursively remove a directory
     *
     * @param $location
     */
    public static function removeDirectory($location)
    {
        // Try to delete using rm if not running under Windows
        // Skip if a protocol is used as this is not supported by rm
        if (
            !defined('PHP_WINDOWS_VERSION_BUILD')
            && !preg_match('/^.+:\/\/.+/', $location)
        ) {
            try {
                $process = Process::fromShellCommandline('rm -rf ' . escapeshellarg($location));
                $process->run();

                return;
            } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
                // Removal using rm failed. Since this may have been a problem
                // with environment and not removal, pass to let PHP try
            }
        }

        if (file_exists($location)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($location),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if (in_array($file->getBasename(), ['.', '..'])) {
                    continue;
                } elseif ($file->isFile() || $file->isLink()) {
                    unlink($file->getPathname());
                } elseif ($file->isDir()) {
                    rmdir($file->getPathname());
                }
            }
            rmdir($location);
        }
    }
    /**
     * @param         $question
     * @param  null   $default
     * @return string
     */
    public static function getQuestion($question, $default = null)
    {
        if ($default !== null) {
            return sprintf(
                '<question>%s</question> [<comment>%s</comment>]: ',
                $question,
                $default
            );
        } else {
            return sprintf(
                '<question>%s</question>: ',
                $question
            );
        }
    }
    /**
     * @param        $extension
     * @param string $message
     * @throws InvalidEnvironmentException
     */
    public static function checkExtension($extension, $message = "Beam requires the '%s' extension")
    {
        if (!extension_loaded($extension)) {
            throw new InvalidEnvironmentException(
                sprintf(
                    $message,
                    $extension
                )
            );
        }
    }

    /**
     * Determines if a command exists on the current environment
     *
     * @param string $command The command to check
     * @return bool True if the command has been found ; otherwise, false.
     */
    public static function commandExists(string $command): bool
    {
        $whereIsCommand = (PHP_OS == 'WINNT') ? 'where' : 'which';

        $process = proc_open(
            "$whereIsCommand $command",
            [
                0 => ["pipe", "r"], //STDIN
                1 => ["pipe", "w"], //STDOUT
                2 => ["pipe", "w"], //STDERR
            ],
            $pipes
        );
        if ($process !== false) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return $stdout != '';
        }

        return false;
    }
}
