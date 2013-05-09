<?php

namespace Heyday\Component\Beam\Deployment;

use Heyday\Component\Beam\Config\DeploymentResultConfiguration;
use Symfony\Component\Config\Definition\Processor;

/**
 * Class DeploymentResult
 * @package Heyday\Component\Beam\Deployment
 */
class DeploymentResult extends \ArrayObject
{
    /**
     * @param $result
     */
    public function __construct(array $result)
    {
        $processor = new Processor();
        $this->result = $processor->processConfiguration(
            new DeploymentResultConfiguration(),
            array(
                $result
            )
        );
        parent::__construct($result);
    }
}