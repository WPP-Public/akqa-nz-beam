<?php

namespace Heyday\Beam\DeploymentProvider;

use Heyday\Beam\Config\DeploymentResultConfiguration;
use Heyday\Beam\Exception\InvalidArgumentException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Class DeploymentResult
 * @package Heyday\Beam\DeploymentProvider
 */
class DeploymentResult extends \ArrayObject
{
    protected ?array $updateCounts = null;

    protected DeploymentResultConfiguration $configuration;

    /**
     * If result is tied to a specific node, name of this node
     *
     * @var string|null
     */
    protected $name = null;

    /**
     * Nested result items
     *
     * @var DeploymentResult[]
     */
    protected $nestedResults = [];

    /**
     * @param $result
     */
    public function __construct(array $result)
    {
        $processor = new Processor();
        $this->configuration = new DeploymentResultConfiguration();

        $processor->processConfiguration(
            $this->configuration,
            $result
        );

        parent::__construct($result);
    }

    /**
     * @param string $type
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function getUpdateCount($type)
    {
        $types = $this->configuration->getUpdates();
        if (!in_array($type, $types)) {
            throw new InvalidArgumentException(
                sprintf(
                    "Update type '%s' doesn't exist",
                    $type
                )
            );
        }
        if (null === $this->updateCounts) {
            $this->updateCounts = array_fill_keys($this->configuration->getUpdates(), 0);
            foreach ($this as $result) {
                $this->updateCounts[$result['update']]++;
            }
        }

        return $this->updateCounts[$type];
    }

    /**
     * @return DeploymentResultConfiguration
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * @param null|string $name
     * @return DeploymentResult
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get all nested results
     *
     * @return DeploymentResult[]
     */
    public function getNestedResults()
    {
        return $this->nestedResults ?: [$this];
    }

    /**
     * @param DeploymentResult[] $nestedResults
     * @return DeploymentResult
     */
    public function setNestedResults($nestedResults)
    {
        $this->nestedResults = $nestedResults;
        return $this;
    }
}
