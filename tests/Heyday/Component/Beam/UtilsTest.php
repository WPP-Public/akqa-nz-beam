<?php

namespace Heyday\Component\Beam;

use org\bovigo\vfs\vfsStream;

class UtilsTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
    }
    public function testGetFilesFromDirectory()
    {
        vfsStream::setup(
            'root',
            0755,
            array(
                'test' => array(
                    'test' => 'content',
                    'test2' => array()
                )
            )
        );
        $this->assertEquals(
            array(
                new \SplFileInfo(vfsStream::url('root/test/test'))
            ),
            Utils::getFilesFromDirectory(
                function ($file) {
                    return true;
                },
                vfsStream::url('root/test')
            )
        );
        $this->assertEquals(
            array(),
            Utils::getFilesFromDirectory(
                function ($file) {
                    return false;
                },
                vfsStream::url('root/test')
            )
        );
    }
    public function testGetAllowedFilesFromDirectory()
    {
        vfsStream::setup(
            'root',
            0755,
            array(
                'test' => array(
                    'test' => 'content',
                    'test2' => 'content',
                    'hello' => 'content',
                )
            )
        );
        $this->assertEquals(
            array(
                new \SplFileInfo(vfsStream::url('root/test/test2')),
                new \SplFileInfo(vfsStream::url('root/test/hello'))
            ),
            Utils::getAllowedFilesFromDirectory(
                array(
                    '/test'
                ),
                vfsStream::url('root/test')
            )
        );
    }
    public function testIsExcluded()
    {
        $this->assertTrue(
            Utils::isExcluded(
                array(
                    '/test'
                ),
                'test'
            )
        );
        $this->assertFalse(
            Utils::isExcluded(
                array(
                    '/test'
                ),
                'test2'
            )
        );
        $this->assertTrue(
            Utils::isExcluded(
                array(
                    '/test/'
                ),
                'test/blah'
            )
        );
        $this->assertTrue(
            Utils::isExcluded(
                array(
                    'test/'
                ),
                'test/blah'
            )
        );
        $this->assertTrue(
            Utils::isExcluded(
                array(
                    '*'
                ),
                'test'
            )
        );
        $this->assertTrue(
            Utils::isExcluded(
                array(
                    'filename.file'
                ),
                'filename.file'
            )
        );
        $this->assertTrue(
            Utils::isExcluded(
                array(
                    'filename.file'
                ),
                'directory/filename.file'
            )
        );
    }
}
