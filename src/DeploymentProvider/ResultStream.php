<?php

namespace Heyday\Beam\DeploymentProvider;

interface ResultStream
{
    /**
     * Set a callback function to receive a stream of changes
     * @param \Closure $callback function(array $array)
     */
    public function setStreamHandler(\Closure $callback = null);
}
