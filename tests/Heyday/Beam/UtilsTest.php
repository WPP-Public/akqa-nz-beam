<?php

namespace Heyday\Beam;

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
            Utils::isFileExcluded(
                array(
                    '/test'
                ),
                'test'
            )
        );
        $this->assertFalse(
            Utils::isFileExcluded(
                array(
                    '/test'
                ),
                'test2'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                array(
                    '/test/'
                ),
                'test/blah'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                array(
                    'test/'
                ),
                'test/blah'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                array(
                    '*'
                ),
                'test'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                array(
                    'filename.file'
                ),
                'filename.file'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                array(
                    'filename.file'
                ),
                'directory/filename.file'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                array(
                    'filename*'
                ),
                'directory/filename.file'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                array(
                    'vendor'// => '*/vendor/*'
                ),
                'vendor/filename.file'
            )
        );
    }
    public function testGetRelativePath()
    {
        $this->assertEquals(
            'vendor/filename.php',
            Utils::getRelativePath('/home', '/home/vendor/filename.php')
        );
    }

    public function testGetFilteredChecksums()
    {
        $this->assertEquals(
            array(

            ),
            Utils::getFilteredChecksums(
                array(
                    'test.json'
                ),
                array(
                    'test.json' => 'sssss'
                )
            )
        );
    }

    public function testChecksumsFromFiles()
    {
        vfsStream::setup(
            'root',
            0755,
            array(
                'test' => array(
                    'test' => 'content',
                    'test2' => 'content',
                    'test3' => 'content',
                )
            )
        );
        $this->assertEquals(
            array(
                'test/test' => '9a0364b9e99bb480dd25e1f0284c8555',
                'test/test2' => '9a0364b9e99bb480dd25e1f0284c8555',
                'test/test3' => '9a0364b9e99bb480dd25e1f0284c8555'
            ),
            Utils::checksumsFromFiles(
                array(
                    new \SplFileInfo(vfsStream::url('root/test/test')),
                    new \SplFileInfo(vfsStream::url('root/test/test2')),
                    new \SplFileInfo(vfsStream::url('root/test/test3'))
                ),
                vfsStream::url('root')
            )
        );
    }
}
