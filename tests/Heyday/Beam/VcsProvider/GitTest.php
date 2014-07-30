<?php

namespace Heyday\Beam\VcsProvider;

use org\bovigo\vfs\vfsStream;

class GitTest extends \PHPUnit_Framework_TestCase
{
    protected $gitMock;

    protected function setUp()
    {
        $this->gitMock = $this->getMock(
            __NAMESPACE__ . '\Git',
            array(
                'process',
                'getUserIdentity'
            ),
            array(),
            '',
            false
        );
    }
    public function testGetCurrentBranch()
    {
        $processMock = $this->getMock(
            'Symfony\Component\Process\Process',
            array(),
            array(),
            '',
            false
        );
        $processMock->expects($this->once())
            ->method('getOutput')
            ->will($this->returnValue(
                    <<<OUTPUT
   master
OUTPUT
        ));

        $this->gitMock->expects($this->once())
            ->method('process')
            ->with($this->equalTo('git rev-parse --abbrev-ref HEAD'))
            ->will($this->returnValue($processMock));

        $this->assertEquals('master', $this->gitMock->getCurrentBranch());

    }

    public function testGetAvailableBranches()
    {
        $processMock = $this->getMock(
            'Symfony\Component\Process\Process',
            array(),
            array(),
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
            array(
                'test',
                'master',
                'remotes/origin/HEAD',
                'remotes/origin/test',
                'remotes/origin/master'
            ),
            $this->gitMock->getAvailableBranches()
        );

    }

    public function testExists()
    {
        vfsStream::setup(
            'root',
            0755,
            array(
                '.git' => array()
            )
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
            array(
                '.git' => array(
                    'hello' => 'cscs'
                )
            )
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
    /**
     * @expectedException \Heyday\Beam\Exception\InvalidConfigurationException
     * @expectedExceptionMessage The git vcs provider can only update remotes
     */
    public function testUpdateBranchException()
    {
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
        $processMock = $this->getMock(
            'Symfony\Component\Process\Process',
            array(),
            array(),
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
