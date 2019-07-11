<?php

namespace Heyday\Beam\Helper;

use Symfony\Component\Console\Helper\FormatterHelper;

class DeploymentResultHelperTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var DeploymentResultHelper
     */
    protected $object;

    /**
     * Shiv for deprecated getMock()
     *
     * @param string $originalClassName
     * @param array  $methods
     * @param array  $arguments
     * @param string $mockClassName
     * @param bool   $callOriginalConstructor
     * @param bool   $callOriginalClone
     * @param bool   $callAutoload
     * @param bool   $cloneArguments
     * @param bool   $callOriginalMethods
     * @param null   $proxyTarget
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function getMock($originalClassName, $methods = [], array $arguments = [], $mockClassName = '', $callOriginalConstructor = true, $callOriginalClone = true, $callAutoload = true, $cloneArguments = false, $callOriginalMethods = false, $proxyTarget = null)
    {
        $mockObject = $this->getMockObjectGenerator()->getMock(
            $originalClassName,
            $methods,
            $arguments,
            $mockClassName,
            $callOriginalConstructor,
            $callOriginalClone,
            $callAutoload,
            $cloneArguments,
            $callOriginalMethods,
            $proxyTarget
        );

        $this->registerMockObject($mockObject);

        return $mockObject;
    }

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
        $this->object = new DeploymentResultHelper($this->getMock(FormatterHelper::class));
    }

    /**
     * @covers Heyday\Beam\Helper\DeploymentResultHelper::getName
     * @todo   Implement testGetName().
     */
    public function testGetName()
    {
        $this->assertEquals('deploymentresult', $this->object->getName());
    }
}
