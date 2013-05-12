<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;

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
