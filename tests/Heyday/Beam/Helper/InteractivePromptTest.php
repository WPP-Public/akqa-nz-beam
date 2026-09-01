<?php

namespace Heyday\Beam\Helper;

use PHPUnit\Framework\TestCase;

class InteractivePromptTest extends TestCase
{
    public function testParseChoiceByIndex(): void
    {
        $this->assertSame(
            'live',
            InteractivePrompt::parseChoice('0', ['live', 'staging', InteractivePrompt::CANCEL_LABEL])
        );
    }

    public function testParseChoiceByName(): void
    {
        $this->assertSame(
            'staging',
            InteractivePrompt::parseChoice('staging', ['live', 'staging', InteractivePrompt::CANCEL_LABEL])
        );
    }

    public function testParseChoiceCancel(): void
    {
        $this->assertSame(
            InteractivePrompt::CANCEL_LABEL,
            InteractivePrompt::parseChoice('cancel', ['live', InteractivePrompt::CANCEL_LABEL])
        );
    }

    public function testParseChoiceRejectsInvalid(): void
    {
        $this->expectException(\Heyday\Beam\Exception\InvalidArgumentException::class);
        InteractivePrompt::parseChoice('99', ['live', InteractivePrompt::CANCEL_LABEL]);
    }

    public function testReadLineFromStreamAcceptsCarriageReturn(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'FwoGZXIvYXdzE3token/with+slashes/and=equals==');
        fseek($stream, 0);

        $this->assertSame(
            'FwoGZXIvYXdzE3token/with+slashes/and=equals==',
            InteractivePrompt::readLineFromStream($stream)
        );

        fclose($stream);
    }

    public function testReadLineFromStreamAcceptsCarriageReturnTerminator(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "token\r");
        fseek($stream, 0);

        $this->assertSame('token', InteractivePrompt::readLineFromStream($stream));

        fclose($stream);
    }

    public function testReadLineFromStreamAcceptsWindowsLineEnding(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "token\r\n");
        fseek($stream, 0);

        $this->assertSame('token', InteractivePrompt::readLineFromStream($stream));

        fclose($stream);
    }

    public function testReadLineFromStreamSkipsLeadingLfAfterCrLf(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "first\r\nsecond");
        fseek($stream, 0);

        $this->assertSame('first', InteractivePrompt::readLineFromStream($stream));
        $this->assertSame('second', InteractivePrompt::readLineFromStream($stream));

        fclose($stream);
    }
}
