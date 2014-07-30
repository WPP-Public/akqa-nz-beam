<?php

namespace Heyday\Beam\DeploymentProvider;

use Heyday\Beam\Beam;

/**
 * Class Deployment
 * @package Heyday\Beam\DeploymentProvider
 */
abstract class Deployment
{
    /**
     * @var \Heyday\Beam\Beam
     */
    protected $beam;
    /**
     * @param \Heyday\Beam\Beam $beam
     */
    public function setBeam(Beam $beam)
    {
        $this->beam = $beam;
    }
}
