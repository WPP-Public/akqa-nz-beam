<?php

namespace Heyday\Beam;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public function testGetFilesFromDirectory()
    {
        vfsStream::setup(
            'root',
            0755,
            [
                'test' => [
                    'test' => 'content',
                    'test2' => []
                ]
            ]
        );
        $this->assertEquals(
            [
                new \SplFileInfo(vfsStream::url('root/test/test'))
            ],
            Utils::getFilesFromDirectory(
                function ($file) {
                    return true;
                },
                vfsStream::url('root/test')
            )
        );
        $this->assertEquals(
            [],
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
            [
                'test' => [
                    'test' => 'content',
                    'test2' => 'content',
                    'hello' => 'content',
                ]
            ]
        );
        $this->assertEquals(
            [
                new \SplFileInfo(vfsStream::url('root/test/test2')),
                new \SplFileInfo(vfsStream::url('root/test/hello'))
            ],
            Utils::getAllowedFilesFromDirectory(
                [
                    '/test'
                ],
                vfsStream::url('root/test')
            )
        );
    }
    public function testIsExcluded()
    {
        $this->assertTrue(
            Utils::isFileExcluded(
                [
                    '/test'
                ],
                'test'
            )
        );
        $this->assertFalse(
            Utils::isFileExcluded(
                [
                    '/test'
                ],
                'test2'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                [
                    '/test/'
                ],
                'test/blah'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                [
                    'test/'
                ],
                'test/blah'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                [
                    '*'
                ],
                'test'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                [
                    'filename.file'
                ],
                'filename.file'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                [
                    'filename.file'
                ],
                'directory/filename.file'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                [
                    'filename*'
                ],
                'directory/filename.file'
            )
        );
        $this->assertTrue(
            Utils::isFileExcluded(
                [
                    'vendor'// => '*/vendor/*'
                ],
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
            [

            ],
            Utils::getFilteredChecksums(
                [
                    'test.json'
                ],
                [
                    'test.json' => 'sssss'
                ]
            )
        );
    }

    public function testChecksumsFromFiles()
    {
        vfsStream::setup(
            'root',
            0755,
            [
                'test' => [
                    'test' => 'content',
                    'test2' => 'content',
                    'test3' => 'content',
                ]
            ]
        );
        $this->assertEquals(
            [
                'test/test' => '9a0364b9e99bb480dd25e1f0284c8555',
                'test/test2' => '9a0364b9e99bb480dd25e1f0284c8555',
                'test/test3' => '9a0364b9e99bb480dd25e1f0284c8555'
            ],
            Utils::checksumsFromFiles(
                [
                    new \SplFileInfo(vfsStream::url('root/test/test')),
                    new \SplFileInfo(vfsStream::url('root/test/test2')),
                    new \SplFileInfo(vfsStream::url('root/test/test3'))
                ],
                vfsStream::url('root')
            )
        );
    }
}
