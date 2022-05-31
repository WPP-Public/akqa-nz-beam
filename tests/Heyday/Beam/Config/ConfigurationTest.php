<?php

namespace Heyday\Beam\Config;

use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    /**
     * @var Configuration
     */
    protected $object;

    protected function setUp(): void
    {
        $this->object = $this->getMockForAbstractClass('Heyday\Beam\Config\Configuration');
    }

    public function testGetFormattedOptions()
    {
        $this->assertEquals(
            "'test1', 'test2'",
            $this->object->getFormattedOptions(
                [
                    'test1',
                    'test2'
                ]
            )
        );
    }
}
