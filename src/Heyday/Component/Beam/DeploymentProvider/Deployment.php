<?php

namespace Heyday\Component\Beam\DeploymentProvider;

use Heyday\Component\Beam\Beam;

/**
 * Class Deployment
 * @package Heyday\Component\Beam\DeploymentProvider
 */
abstract class Deployment
{
    /**
     * @var \Heyday\Component\Beam\Beam
     */
    protected $beam;
    /**
     * @param \Heyday\Component\Beam\Beam $beam
     */
    public function setBeam(Beam $beam)
    {
        $this->beam = $beam;
    }
}
