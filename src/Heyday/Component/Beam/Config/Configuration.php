<?php

namespace Heyday\Component\Beam\Config;

/**
 * Class Configuration
 * @package Heyday\Component\Beam\Config
 */
abstract class Configuration
{
    /**
     * @param         $options
     * @param  string $enclosure
     * @return string
     */
    public function getFormattedOptions($options, $enclosure = '\'')
    {
        return $enclosure . implode("$enclosure, $enclosure", $options) . $enclosure;
    }
}