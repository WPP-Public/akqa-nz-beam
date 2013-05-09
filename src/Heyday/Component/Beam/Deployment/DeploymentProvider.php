<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Beam;

/**
 * Class DeploymentProvider
 * @package Heyday\Component\Beam\Deployment
 */
interface DeploymentProvider
{
    /**
     * @param  Beam  $beam
     * @return mixed
     */
    public function setBeam(Beam $beam);
    /**
     * @param  callable $output
     * @param  bool     $dryrun
     * @return mixed
     */
    public function up(\Closure $output = null, $dryrun = false);
    /**
     * @param  callable $output
     * @param  bool     $dryrun
     * @return mixed
     */
    public function down(\Closure $output = null, $dryrun = false);
    /**
     * @return mixed
     */
    public function getTargetPath();
}
