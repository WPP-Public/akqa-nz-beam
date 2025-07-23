<?php

namespace Heyday\Beam\Config;

use Symfony\Component\Config\Loader\FileLoader;
use Heyday\Beam\Exception\InvalidConfigurationException;
use Seld\JsonLint\JsonParser;

/**
 * Class JsonConfigLoader
 * @package Heyday\Beam\Config
 */
class JsonConfigLoader extends FileLoader
{
    /**
     * @var array
     */
    protected $locateCache = [];

    /**
     * Validate the syntax of a JSON string
     *
     * @param string $json
     * @param string $path
     * @throws \Heyday\Beam\Exception\InvalidConfigurationException
     */
    public static function validateSyntax($json, $path)
    {
        $parser = new JsonParser();
        $result = $parser->lint($json);

        if ($result !== null) {
            throw new InvalidConfigurationException(
                "Failed to parse config $path:" . PHP_EOL . PHP_EOL . $result->getMessage()
            );
        }
    }

    /**
     * Loads a resource.
     *
     * @param  mixed  $resource The resource
     * @param  string $type     The resource type
     * @throws InvalidConfigurationException
     * @return array
     */
    public function load($resource, $type = null): array
    {
        $path =  $this->locate($resource);
        $json = file_get_contents($path);

        $config = json_decode($json, true);

        if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
            self::validateSyntax($json, $path);
        }

        return $config;
    }
    /**
     * @param $resource
     * @return array|string
     */
    public function locate($resource)
    {
        if (!isset($this->locateCache[$resource])) {
            $this->locateCache[$resource] = $this->locator->locate(
                $resource
            );
        }

        return $this->locateCache[$resource];
    }
    /**
     * Returns true if this class supports the given resource.
     *
     * @param mixed  $resource A resource
     * @param string $type     The resource type
     *
     * @return Boolean true if this class supports the given resource, false otherwise
     */
    public function supports($resource, $type = null): bool
    {
        return is_string($resource) && 'json' === pathinfo(
            $resource,
            PATHINFO_EXTENSION
        );
    }
}
