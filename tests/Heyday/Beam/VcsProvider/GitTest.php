<?php

namespace Heyday\Beam\VcsProvider;

use Heyday\Beam\Exception\InvalidConfigurationException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitTest extends TestCase
{
    protected $gitMock;


    protected function setUp(): void
    {
        $this->gitMock = $this->createPartialMock(__NAMESPACE__ . '\Git', [
            'process',
            'getUserIdentity'
        ]);
    }

    public function testGetCurrentBranch()
    {
        /** @var MockObject */
        $processMock = $this->createMock(
            Process::class,
            [],
            [],
            '',
            false
        );

        $processMock->method('getOutput')->will($this->returnValue(
            <<<OUTPUT
master
OUTPUT
        ));

        $this->gitMock->expects($this->once())
            ->method('process')
            ->with($this->equalTo('git rev-parse --abbrev-ref HEAD'))
            ->willReturn($processMock);

        $this->assertEquals('master', $this->gitMock->getCurrentBranch());
    }

    public function testGetAvailableBranches()
    {
        $processMock = $this->createMock(
            'Symfony\Component\Process\Process',
            [],
            [],
            '',
            false
        );
        $processMock->expects($this->once())
            ->method('getOutput')
            ->will($this->returnValue(
                <<<OUTPUT
* test
  master
  remotes/origin/HEAD -> origin/master
  remotes/origin/test
  remotes/origin/master
OUTPUT
            ));

        $this->gitMock->expects($this->once())
            ->method('process')
            ->will(
                $this->returnValue($processMock)
            );

        $this->assertEquals(
            [
                'test',
                'master',
                'remotes/origin/HEAD',
                'remotes/origin/test',
                'remotes/origin/master'
            ],
            $this->gitMock->getAvailableBranches()
        );
    }

    public function testExists()
    {
        vfsStream::setup(
            'root',
            0755,
            [
                '.git' => []
            ]
        );
        $git = new Git(vfsStream::url('root'));
        $this->assertTrue($git->exists());
        $git = new Git(vfsStream::url('root/test'));
        $this->assertFalse($git->exists());
    }

    public function testExportRef()
    {
        vfsStream::setup(
            'root',
            0755,
            [
                '.git' => [
                    'hello' => 'cscs'
                ]
            ]
        );

        $this->assertTrue(file_exists(vfsStream::url('root/.git/hello')));

        $this->gitMock->expects($this->once())
            ->method('process')
            ->with($this->equalTo('(git archive master) | (cd vfs://root && tar -xf -)'));

        $this->gitMock->exportRef('master', vfsStream::url('root'));

        $this->assertFalse(file_exists(vfsStream::url('root/.git/hello')));
        $this->assertFalse(file_exists(vfsStream::url('root/.git')));
        $this->assertTrue(file_exists(vfsStream::url('root')));
    }


    public function testUpdateBranchException()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The git vcs provider can only update remotes');

        $this->gitMock->updateBranch('master');
    }


    public function testUpdateBranch()
    {
        $this->gitMock->expects($this->once())
            ->method('process')
            ->with($this->equalTo('git remote update --prune origin'));

        $this->gitMock->updateBranch('remotes/origin/master');
    }

    public function testGetLog()
    {
        /** @var MockObject */
        $processMock = $this->createMock(
            'Symfony\Component\Process\Process',
            [],
            [],
            '',
            false
        );
        $processMock->expects($this->once())
            ->method('getOutput')
            ->will($this->returnValue(
                <<<OUTPUT
commit 4627bea545766a6a50abffa0512aa0c0a7c85158
Merge: 1d25c4e 0c85469
Author: Author <email@test.com>
Date:   Sun Apr 21 18:11:28 2013 -0700

    Test date
OUTPUT
            ));

        $this->gitMock->expects($this->once())
            ->method('process')
            ->with($this->equalTo('git log -1 --format=medium master'))
            ->will($this->returnValue($processMock));

        $this->gitMock->expects($this->atLeastOnce())
            ->method('getUserIdentity')
            ->will($this->returnValue('Joe Bloggs <joe.bloggs@example.com>'));

        $user = $this->gitMock->getUserIdentity();
        $this->assertEquals(
            <<<OUTPUT
Deployer: $user
Ref: master
commit 4627bea545766a6a50abffa0512aa0c0a7c85158
Merge: 1d25c4e 0c85469
Author: Author <email@test.com>
Date:   Sun Apr 21 18:11:28 2013 -0700

    Test date

OUTPUT
            ,
            $this->gitMock->getLog('master')
        );
    }

    public function testIsRemote()
    {
        $git = new Git('test');
        $this->assertTrue($git->isRemote('remotes/hello/master'));
        $this->assertFalse($git->isRemote('remotes/hello'));
        $this->assertFalse($git->isRemote('master'));
    }
}
