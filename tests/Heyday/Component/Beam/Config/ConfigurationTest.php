<?php

namespace Heyday\Component\Beam\Config;

class ConfigurationTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Configuration
     */
    protected $object;

    protected function setUp()
    {
        $this->object = $this->getMockForAbstractClass('Heyday\Component\Beam\Config\Configuration');
    }

    public function testGetFormattedOptions()
    {
        $this->assertEquals(
            "'test1', 'test2'",
            $this->object->getFormattedOptions(
                array(
                    'test1',
                    'test2'
                )
            )
        );
    }
}
