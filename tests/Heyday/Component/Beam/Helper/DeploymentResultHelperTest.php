<?php

namespace Heyday\Component\Beam\Helper;

class DeploymentResultHelperTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var DeploymentResultHelper
     */
    protected $object;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
        $this->object = new DeploymentResultHelper($this->getMock('Symfony\Component\Console\Helper\FormatterHelper'));
    }

    /**
     * @covers Heyday\Component\Beam\Helper\DeploymentResultHelper::getName
     * @todo   Implement testGetName().
     */
    public function testGetName()
    {
        $this->assertEquals('deploymentresult', $this->object->getName());
    }

    /**
     * @covers Heyday\Component\Beam\Helper\DeploymentResultHelper::outputChanges
     * @todo   Implement testOutputChanges().
     */
    public function testOutputChanges()
    {
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete(
          'This test has not been implemented yet.'
        );
    }

    /**
     * @covers Heyday\Component\Beam\Helper\DeploymentResultHelper::outputChangesSummary
     * @todo   Implement testOutputChangesSummary().
     */
    public function testOutputChangesSummary()
    {
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete(
          'This test has not been implemented yet.'
        );
    }
}
