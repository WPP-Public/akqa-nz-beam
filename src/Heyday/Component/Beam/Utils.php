<?php

namespace Heyday\Component\Beam;

class Utils
{
    public static function getAllFiles($dir)
    {
        $files = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if (in_array($file->getBasename(), array('.', '..'))) {
                continue;
            } elseif ($file->isFile() || $file->isLink()) {
                $files[] = $file;
            }
        }
        return $files;
    }
}