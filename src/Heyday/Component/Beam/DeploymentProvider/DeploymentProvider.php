<?php

namespace Heyday\Component\Beam\DeploymentProvider;

use Heyday\Component\Beam\Beam;

/**
 * Class DeploymentProvider
 * @package Heyday\Component\Beam\DeploymentProvider
 */
interface DeploymentProvider
{

    const LIMITATION_REMOTECOMMAND = 'remote-command';

    /**
     * @param  Beam $beam
     * @return mixed
     */
    public function setBeam(Beam $beam);
    /**
     * @param  callable $output
     * @param  bool     $dryrun
     * @return mixed
     */
    public function up(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null);
    /**
     * @param  callable $output
     * @param  bool     $dryrun
     * @return mixed
     */
    public function down(\Closure $output = null, $dryrun = false, DeploymentResult $deploymentResult = null);
    /**
     * @return mixed
     */
    public function getTargetPath();
    /**
     * Return any limitations of the provider
     * @return array
     */
    public function getLimitations();

}
