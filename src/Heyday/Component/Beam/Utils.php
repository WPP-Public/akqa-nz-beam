<?php

namespace Heyday\Component\Beam;

/**
 * Class Utils
 * @package Heyday\Component\Beam
 */
class Utils
{
    /**
     * @param callable $condition
     * @param          $dir
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
            if (in_array($file->getBasename(), array('.', '..'))) {
                continue;
            } elseif ($condition($file)) {
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
    public static function getAllowedFilesFromDirectory($excludes, $dir, $include = false)
    {
        return Utils::getFilesFromDirectory(
            function ($file) use ($excludes, $dir, $include) {
                $path = Utils::getRelativePath(
                    $dir,
                    $file->getPathname()
                );
                return ($file->isFile() || $file->isLink()) && !Utils::isExcluded(
                    $excludes,
                    $path
                ) && (!$include || preg_match("{^$include}", $path));
            },
            $dir
        );
    }
    /**
     * @param array $files
     * @param       $dir
     * @return array
     */
    public static function getChecksumForFiles(array $files, $dir)
    {
        $checksums = array();
        foreach ($files as $file) {
            $path = $file->getPathname();
            $checksums[Utils::getRelativePath($dir, $path)] = md5_file($path);
        }
        return $checksums;
    }
    /**
     * @param $excludes
     * @param $path
     * @return bool
     */
    public static function isExcluded($excludes, $path)
    {
        foreach ($excludes as $exclude) {
            if ($exclude[0] == '/' && substr($exclude, -1) == '/') {
                if (strpos('/' . $path, $exclude) === 0) {
                    return true;
                }
            } elseif(substr($exclude, -1) == '/') {
                if (strpos('/' . $path, $exclude) !== false) {
                    return true;
                }
            } elseif(fnmatch('*' . $exclude, $path)) {
                return true;
            }
        }
        return false;
    }
    /**
     * @param $root
     * @param $path
     * @return mixed
     */
    public static function getRelativePath($root, $path)
    {
        return str_replace($root . '/', '', $path);
    }
    /**
     * @param array $checksums
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
     * @param array $checksums
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
}