<?php namespace WebSockets\Tests;

use PHPUnit\Framework\TestCase;
use Ollyxar\WebSockets\Frame;

class FrameTest extends TestCase
{
    const TEXT_FILE = 'php://memory';

    public function testTranslateFunctions()
    {
        $encoded = Frame::encode('simple text');
        $handle = fopen(self::TEXT_FILE, 'r+');
        fwrite($handle, $encoded);
        rewind($handle);
        $decoded = Frame::decode($handle);
        fclose($handle);

        $this->assertTrue($decoded['opcode'] === Frame::TEXT);
        $this->assertTrue($decoded['payload'] === 'simple text');
    }
}