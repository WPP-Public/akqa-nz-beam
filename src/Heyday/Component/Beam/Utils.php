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
    public static function getFilesFromDirectory(\Closure $condition, $dir) //TODO: Rename function
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
}