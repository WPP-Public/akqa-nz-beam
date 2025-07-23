<?php
require_once __DIR__ . '/composer/ClassLoader.php';
$dir = dirname(__DIR__);
$loader = new Composer\Autoload\ClassLoader;
$loader->addClassMap($map);
$loader->register(true);
return $loader;
