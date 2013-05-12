<?php

namespace Heyday\Component\Beam;

/**
 * Class Utils
 * @package Heyday\Component\Beam
 */
class Utils
{
    /**
     * @param  callable $condition
     * @param           $dir
     * @return array
     */
    public static function getFilesFromDirectory(\Closure $condition, $dir)
    {
        $files = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if (!in_array($file->getBasename(), array('.', '..')) && ($file->isFile() || $file->isLink()) && $condition(
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
     * @param $dir
     * @return array
     */
    public static function getAllowedFilesFromDirectory(array $excludes, $dir)
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
     * @param $path A relative path
     * @return bool
     */
    public static function isFileExcluded(array $patterns, $path)
    {
        $path = '/' . $path;
        foreach ($patterns as $pattern) {
            if (substr($pattern, -1) === '/') {
                $pattern = $pattern . '*';
                if ($pattern[0] !== '/') {
                    $pattern = '*' . $pattern;
                }
            } elseif ($pattern[0] !== '/') {
                $pattern = '*/' . $pattern;
            }

            if (fnmatch($pattern, $path)) {
                return true;
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
        $checksums = array();
        foreach ($files as $file) {
            $path = $file->getPathname();
            $checksums[Utils::getRelativePath($dir, $path)] = md5_file($path);
        }

        return $checksums;
    }
    /**
     * @param  array $checksums
     * @return mixed
     */
    public static function checksumsToBz2(array $checksums)
    {
        return bzcompress(json_encode($checksums), 9);
    }
    /**
     * @param $data
     * @return string
     */
    public static function checksumsFromBz2($data)
    {
        return json_decode(bzdecompress($data), true);
    }
    /**
     * @param  array  $checksums
     * @return string
     */
    public static function checksumsToGz(array $checksums)
    {
        return gzencode(json_encode($checksums), 9);
    }
    /**
     * @param $data
     * @return mixed
     */
    public static function checksumsFromGz($data)
    {
        return json_decode(gzinflate(substr($data, 10, -8)), true);
    }
    /**
     * @param $data
     * @return mixed
     */
    public static function checksumsFromString($data)
    {
        return json_decode($data, true);
    }
}
