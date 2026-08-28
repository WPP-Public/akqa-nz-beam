<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Exception\RuntimeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class StatusCommandTest extends TestCase
{
    private string $cwd;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->tmpDir = sys_get_temp_dir() . '/beam-test-' . uniqid();
        mkdir($this->tmpDir);
        file_put_contents(
            $this->tmpDir . '/beam.json',
            json_encode([
                'servers' => [
                    'live' => [
                        'host' => 'example.com',
                        'webroot' => '/var/www',
                    ],
                ],
            ])
        );
        chdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        unlink($this->tmpDir . '/beam.json');
        rmdir($this->tmpDir);
    }

    public function testShowsBeamlogAndContainingBranches()
    {
        $command = new class extends StatusCommand {
            protected function readBeamLog(array $server, string $host): string
            {
                return <<<BEAMLOG
Deployer: stevie
Ref: remotes/origin/master
commit c4bbafdc440d538ffa669f9e873ff9708e074b7e
Author: Dr. Stevie Mayhew <stevie@example.com>
Date:   Thu Sep 5 13:00:46 2013 +1200

    Enhanced comments with memes
BEAMLOG;
            }

            protected function getBranchesContaining(string $commit, string $srcDir): array
            {
                return ['master', 'remotes/origin/master'];
            }
        };

        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute(['target' => 'live']));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Deployer: stevie', $display);
        $this->assertStringContainsString('commit c4bbafdc440d538ffa669f9e873ff9708e074b7e', $display);
        $this->assertStringContainsString('This commit appears in:', $display);
        $this->assertStringContainsString('    master', $display);
        $this->assertStringContainsString('    remotes/origin/master', $display);
    }

    public function testThrowsWhenBeamlogDoesNotContainACommit()
    {
        $command = new class extends StatusCommand {
            protected function readBeamLog(array $server, string $host): string
            {
                return "Deployer: stevie\nRef: remotes/origin/master\n";
            }

            protected function getBranchesContaining(string $commit, string $srcDir): array
            {
                return [];
            }
        };

        $tester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not find a commit hash in .beamlog');
        $tester->execute(['target' => 'live']);
    }
}
