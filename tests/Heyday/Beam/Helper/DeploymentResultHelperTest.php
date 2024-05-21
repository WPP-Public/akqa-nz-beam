<?php

namespace Heyday\Beam\Helper;

use Symfony\Component\Console\Helper\FormatterHelper;
use PHPUnit\Framework\TestCase;

class DeploymentResultHelperTest extends TestCase
{
    /**
     * @var DeploymentResultHelper
     */
    protected $object;


    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp(): void
    {
        $this->object = new DeploymentResultHelper($this->createMock(FormatterHelper::class));
    }

    /**
     *
     */
    public function testGetName()
    {
        $this->assertEquals('deploymentresult', $this->object->getName());
    }
}
