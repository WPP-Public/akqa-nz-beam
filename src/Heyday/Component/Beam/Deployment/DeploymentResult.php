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
     * @var
     */
    protected $updateCounts;
    /**
     * @var
     */
    protected $configuration;
    /**
     * @param $result
     */
    public function __construct(array $result)
    {
        $processor = new Processor();
        $this->result = $processor->processConfiguration(
            $this->configuration = new DeploymentResultConfiguration(),
            array(
                $result
            )
        );
        parent::__construct($result);
    }
    /**
     * @param $type
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function getUpdateCount($type)
    {
        $types = $this->configuration->getUpdates();
        if (!in_array($type, $types)) {
            throw new \InvalidArgumentException("Update type '$type' doesn't exist");
        }
        if (null === $this->updateCounts) {
            $this->updateCounts = array_fill_keys($this->configuration->getUpdates(), 0);
            foreach ($this as $result) {
                $this->updateCounts[$result['update']]++;
            }
        }
        return $this->updateCounts[$type];
    }
}
