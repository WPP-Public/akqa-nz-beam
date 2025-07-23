<?php

namespace Heyday\Beam\DeploymentProvider;

use Closure;
use Heyday\Beam\Beam;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class DeploymentProvider
 * @package Heyday\Beam\DeploymentProvider
 */
interface DeploymentProvider
{
    public const LIMITATION_REMOTECOMMAND = 'remote-command';

    /**
     * @param  Beam $beam
     * @return mixed
     */
    public function setBeam(Beam $beam);

    /**
     * @param  \Closure|null $output
     * @param  bool $dryrun
     * @param DeploymentResult|null $deploymentResult
     * @return DeploymentResult
     */
    public function up(?\Closure $output = null, $dryrun = false, ?DeploymentResult $deploymentResult = null);

    /**
     * @param  \Closure|null $output
     * @param  bool $dryrun
     * @param DeploymentResult|null $deploymentResult
     * @return mixed
     */
    public function down(?\Closure $output = null, $dryrun = false, ?DeploymentResult $deploymentResult = null);

    public function getTargetPath(): string;

    /**
     * Gets the to location for rsync for all hostnames (supports multiple hosts)
     *
     * @return array
     */
    public function getTargetPaths(): array;

    /**
     * Return a string representation of the target
     * @return string
     */
    public function getTargetAsText(): string;

    /**
     * Return any limitations of the provider
     * @return array
     */
    public function getLimitations(): array;

    /**
     * Allow post-init configuration of the deployment provider
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function configure(InputInterface $input, OutputInterface $output);
}
