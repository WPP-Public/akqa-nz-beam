<?php

namespace Heyday\Beam\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

class TransferCommandTest extends TestCase
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
                    'staging' => [
                        'host' => 'staging.example.com',
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

    public function testPromptsForTargetWhenOmitted()
    {
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs(['staging']);

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame('staging', $tester->getInput()->getArgument('target'));
        $this->assertStringContainsString('Which target do you want to beam to?', $tester->getDisplay());
    }

    public function testDoesNotPromptWhenTargetIsProvided()
    {
        $tester = new CommandTester($this->createCommand());

        $this->assertSame(0, $tester->execute(['target' => 'live']));
        $this->assertSame('live', $tester->getInput()->getArgument('target'));
        $this->assertStringNotContainsString('Which target', $tester->getDisplay());
    }

    public function testRejectsUnknownTarget()
    {
        $tester = new CommandTester($this->createCommand());

        $this->expectException(InvalidOptionsException::class);
        $this->expectExceptionMessage('Accepted values are: "live", "staging"');

        $tester->execute(['target' => 'production']);
    }

    public function testRequiresTargetWhenNotInteractive()
    {
        $tester = new CommandTester($this->createCommand(true));

        $this->expectException(\Heyday\Beam\Exception\RuntimeException::class);
        $this->expectExceptionMessage('The "target" argument is required.');

        $tester->execute([], ['interactive' => false]);
    }

    /**
     * @param bool $useRealExecute
     */
    private function createCommand($useRealExecute = false): TransferCommand
    {
        if ($useRealExecute) {
            $command = new UpCommand();
        } else {
            $command = new class extends TransferCommand {
                protected function configure()
                {
                    parent::configure();
                    $this->setName('up');
                }

                protected function getDirection(): string
                {
                    return 'up';
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return Command::SUCCESS;
                }
            };
        }

        return $command;
    }
}
