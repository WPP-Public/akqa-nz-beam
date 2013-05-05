<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;

interface DeploymentProvider
{
    public function setBeam(Beam $beam);
    public function deploy(\Closure $output = null, $dryrun = false);
    public function getRemotePath();
}