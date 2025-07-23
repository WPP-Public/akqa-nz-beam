<?php

namespace Heyday\Beam;

use Heyday\Beam\Exception\InvalidArgumentException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

class BeamTest extends TestCase
{
    protected $validConfig;

    protected $validOptions;

    public function setUp(): void
    {
        vfsStream::setup(
            'root',
            0777,
            [
                'test' => []
            ]
        );

        $this->validConfig = [
            'servers' => [
                'live' => [
                    'user'    => 'testuser',
                    'host'    => 'testhost',
                    'webroot' => '/test/webroot'
                ]
            ],
            'exclude' => []
        ];

        $this->validOptions = [
            'direction'          => 'up',
            'target'             => 'live',
            'srcdir'             => vfsStream::url('root/test'),
            'vcsprovider'        => $this->getVcsProviderStub(),
            'deploymentprovider' => $this->getDeploymentProviderStub()
        ];
    }

    /**
     * @param  bool   $exists
     * @param  array  $available
     * @param  string $current
     * @return MockObject
     */
    protected function getVcsProviderStub($exists = true, $available = ['master'], $current = 'master')
    {
        /** @var MockObject */
        $vcsProviderStub = $this->createMock('Heyday\Beam\VcsProvider\GitLikeVcsProvider');
        $vcsProviderStub->expects($this->any())
            ->method('getCurrentBranch')
            ->willReturn($current);
        $vcsProviderStub->expects($this->any())
            ->method('getAvailableBranches')
            ->willReturn($available);
        $vcsProviderStub->expects($this->any())
            ->method('isValidRef')
            ->willReturn(true);
        $vcsProviderStub->expects($this->any())
            ->method('exists')
            ->willReturn($exists);

        return $vcsProviderStub;
    }

    protected function getDeploymentProviderStub()
    {
        $deploymentProviderStub = $this->createMock('Heyday\Beam\DeploymentProvider\DeploymentProvider');

        return $deploymentProviderStub;
    }

    protected function getCombinedOptions($options)
    {
        return array_merge(
            $this->validOptions,
            $options
        );
    }


    public function testBeamConstructMissingOptionsException()
    {
        $this->expectException(MissingOptionsException::class);

        new Beam(
            $this->validConfig,
            []
        );
    }


    public function testBeamConstructInvalidOptionsDirectionException()
    {
        $this->expectException(InvalidOptionsException::class);
        $this->expectExceptionMessage(
            'The option "direction" with value "fake" is invalid. Accepted values are: "up", "down".'
        );

        new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'direction' => 'fake'
                ]
            )
        );
    }


    public function testBeamConstructInvalidOptionsRemoteException()
    {
        $this->expectException(InvalidOptionsException::class);
        $this->expectExceptionMessage(
            'The option "target" with value "fake" is invalid. Accepted values are: "live".'
        );

        new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'target' => 'fake'
                ]
            )
        );
    }


    public function testBeamConstructInvalidLockedBranch()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Specified ref "test" doesn\'t match the locked branch "master"');

        new Beam(
            [
                'servers' => [
                    'live' => [
                        'user'    => 'testuser',
                        'host'    => 'testhost',
                        'webroot' => '/test/webroot',
                        'branch'  => 'master'
                    ]
                ],
                'exclude' => []
            ],
            $this->getCombinedOptions(
                [
                    'ref' => 'test'
                ]
            )
        );
    }


    public function testBeamConstructWorkingCopyLockedRemote()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Working copy can't be used with a locked remote branch");

        $vcsProvider = $this->getVcsProviderStub(
            true,
            [
                'remotes/origin/master'
            ]
        );
        $vcsProvider->expects($this->once())
            ->method('isRemote')
            ->with($this->equalTo('remotes/origin/master'))
            ->willReturn(true);

        new Beam(
            [
                'servers' => [
                    'live' => [
                        'user'    => 'testuser',
                        'host'    => 'testhost',
                        'webroot' => '/test/webroot',
                        'branch'  => 'remotes/origin/master'
                    ]
                ],
                'exclude' => []
            ],
            $this->getCombinedOptions(
                [
                    'working-copy' => true,
                    'vcsprovider'  => $vcsProvider
                ]
            )
        );
    }


    public function testBeamConstructNoVcs()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("You can't use beam without a vcs.");

        new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'vcsprovider' => $this->getVcsProviderStub(false)
                ]
            )
        );
    }


    public function testBeamConstructLocalPathNotWritable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The local path "vfs://root" is not writable');

        vfsStream::setup(
            'root',
            0000,
            [
                'test' => []
            ]
        );
        new Beam(
            $this->validConfig,
            $this->validOptions
        );
    }

    public function testBeamConstruct()
    {
        $this->assertInstanceOf(
            __NAMESPACE__ . '\Beam',
            new Beam(
                $this->validConfig,
                $this->validOptions
            )
        );
    }

    public function testGetLocalPath()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->validOptions
        );
        $this->assertEquals('/tmp/beam-57df3e8661206a673d2fef647599e814', $beam->getLocalPath());
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'path' => 'extra'
                ]
            )
        );
        $this->assertEquals('/tmp/beam-57df3e8661206a673d2fef647599e814', $beam->getLocalPath());
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'working-copy' => true
                ]
            )
        );
        $this->assertEquals('vfs://root/test', $beam->getLocalPath());
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'working-copy' => true,
                    'path'         => 'test/'
                ]
            )
        );
        $this->assertEquals('vfs://root/test', $beam->getLocalPath());
    }

    public function testIsUp()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->validOptions
        );
        $this->assertTrue($beam->isUp());
    }

    public function testIsDown()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'direction' => 'down'
                ]
            )
        );
        $this->assertTrue($beam->isDown());
    }

    public function testIsWorkingCopy()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'working-copy' => true
                ]
            )
        );
        $this->assertTrue($beam->isWorkingCopy());
    }

    public function testIsServerLocked()
    {
        $beam = new Beam(
            [
                'servers' => [
                    'live' => [
                        'user'    => 'testuser',
                        'host'    => 'testhost',
                        'webroot' => '/test/webroot',
                        'branch'  => 'master'
                    ]
                ],
                'exclude' => []
            ],
            $this->validOptions
        );
        $this->assertTrue($beam->isServerLocked());
    }

    public function testIsServerLockedRemote()
    {
        $vcsProvider = $this->getVcsProviderStub(
            true,
            [
                'remotes/origin/master'
            ]
        );
        $vcsProvider->expects($this->once())
            ->method('isRemote')
            ->with($this->equalTo('remotes/origin/master'))
            ->willReturn(true);

        $beam = new Beam(
            [
                'servers' => [
                    'live' => [
                        'user'    => 'testuser',
                        'host'    => 'testhost',
                        'webroot' => '/test/webroot',
                        'branch'  => 'remotes/origin/master'
                    ]
                ],
                'exclude' => []
            ],
            $this->getCombinedOptions(
                [
                    'vcsprovider' => $vcsProvider
                ]
            )
        );
        $this->assertTrue($beam->isTargetLockedRemote());
    }

    public function testIsBranchRemote()
    {
        $vcsProvider = $this->getVcsProviderStub(true, ['remotes/origin/master']);
        $vcsProvider->expects($this->once())
            ->method('isRemote')
            ->with($this->equalTo('remotes/origin/master'))
            ->willReturn(true);
        $beam = new Beam(
            [
                'servers' => [
                    'live' => [
                        'user'    => 'testuser',
                        'host'    => 'testhost',
                        'webroot' => '/test/webroot'
                    ]
                ],
                'exclude' => []
            ],
            $this->getCombinedOptions(
                [
                    'ref'         => 'remotes/origin/master',
                    'vcsprovider' => $vcsProvider
                ]
            )
        );
        $this->assertTrue($beam->isBranchRemote());
    }

    public function testHasPath()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->validOptions,
        );
        $this->assertFalse($beam->hasPath());
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                [
                    'path' => [
                        'test'
                    ]
                ]
            )
        );
        $this->assertTrue($beam->hasPath());
    }

    public function testMatchTag()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->validOptions
        );

        $class = new \ReflectionClass($beam);
        $matchTag = $class->getMethod('matchTag');
        $matchTag->setAccessible(true);

        $tests = [
            'kitten'  => [
                'kitten'         => true,
                'kitties'        => false,
                'kittens-deploy' => false,
                'puppies'        => false
            ],
            'kitten*' => [
                'kitten'         => true,
                'kitties'        => false,
                'kittens-deploy' => true,
                'puppies'        => false
            ],
            'kit*'    => [
                'kitten'         => true,
                'kitties'        => true,
                'kittens-deploy' => true,
                'puppies'        => false
            ],
            '*deploy' => [
                'kitten'               => false,
                'kitties'              => false,
                'kittens-deploy'       => true,
                'deploy-velociraptors' => false,
                'puppies-deploy'       => true
            ]
        ];

        foreach ($tests as $input => $asserts) {
            $beam->setOption('command-tags', [$input]);

            foreach ($asserts as $tag => $expected) {
                $this->assertEquals($expected, $matchTag->invoke($beam, $tag), "Input value '$input' incorrectly matched tag $tag");
            }
        }
    }
}
