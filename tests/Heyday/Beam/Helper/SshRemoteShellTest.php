<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SshRemoteShellTest extends TestCase
{
    public function testBuildDefaultsToSsh(): void
    {
        $this->assertSame('ssh', SshRemoteShell::build([
            'sshpass' => false,
            'ssm' => ['enabled' => false],
        ]));
    }

    public function testBuildUsesSshPass(): void
    {
        $this->assertSame('sshpass -e ssh', SshRemoteShell::build([
            'sshpass' => true,
            'ssm' => ['enabled' => false],
        ]));
    }

    public function testBuildUsesSsmProxyCommand(): void
    {
        $shell = SshRemoteShell::build([
            'sshpass' => false,
            'ssm' => [
                'enabled' => true,
                'region' => 'ap-southeast-2',
                'profile' => 'deploy',
            ],
        ]);

        $expectedProxy = "aws ssm start-session --target %h --document-name AWS-StartSSHSession --parameters portNumber=%p --region ap-southeast-2 --profile deploy";

        $this->assertSame(
            'ssh -o ProxyCommand=' . escapeshellarg($expectedProxy),
            $shell
        );
    }

    public function testBuildRejectsSsmWithSshPass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SshRemoteShell::build([
            'sshpass' => true,
            'ssm' => ['enabled' => true],
        ]);
    }

    public function testBuildSsmProxyCommandRejectsUnsafeTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SshRemoteShell::buildSsmProxyCommand([
            'region' => 'ap-southeast-2;rm -rf /',
        ]);
    }

    public function testIsEnabled(): void
    {
        $this->assertTrue(SshRemoteShell::isEnabled([
            'ssm' => ['enabled' => true],
        ]));
        $this->assertFalse(SshRemoteShell::isEnabled([
            'ssm' => ['enabled' => false],
        ]));
        $this->assertFalse(SshRemoteShell::isEnabled([]));
    }

    public function testProcessSsmBooleanConfig(): void
    {
        $processor = new \Symfony\Component\Config\Definition\Processor();
        $processed = $processor->processConfiguration(
            new \Heyday\Beam\Config\BeamConfiguration(),
            [
                [
                    'servers' => [
                        'live' => [
                            'user' => 'ec2-user',
                            'host' => 'i-0abc123def456',
                            'webroot' => '/var/www/html',
                            'ssm' => true,
                        ],
                    ],
                ],
            ]
        );

        $this->assertTrue($processed['servers']['live']['ssm']['enabled']);
        $this->assertNull($processed['servers']['live']['ssm']['region']);
        $this->assertNull($processed['servers']['live']['ssm']['profile']);
    }

    public function testProcessSsmObjectConfig(): void
    {
        $processor = new \Symfony\Component\Config\Definition\Processor();
        $processed = $processor->processConfiguration(
            new \Heyday\Beam\Config\BeamConfiguration(),
            [
                [
                    'servers' => [
                        'live' => [
                            'user' => 'ec2-user',
                            'host' => 'i-0abc123def456',
                            'webroot' => '/var/www/html',
                            'ssm' => [
                                'region' => 'ap-southeast-2',
                                'profile' => 'deploy',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertEquals(
            [
                'enabled' => true,
                'region' => 'ap-southeast-2',
                'profile' => 'deploy',
            ],
            $processed['servers']['live']['ssm']
        );
    }
}
