<?php namespace WebSockets\Tests;

use PHPUnit\Framework\TestCase;
use Ollyxar\WebSockets\Frame;

class FrameTest extends TestCase
{
    const TEXT_FILE = 'php://memory';

    /**
     * Encode/Decode helper
     *
     * @param string $text
     * @param int $type
     * @return array
     */
    private function encodeDecode(string $text, int $type = Frame::TEXT): array
    {
        $encoded = Frame::encode($text, $type);
        $handle = fopen(self::TEXT_FILE, 'r+');
        fwrite($handle, $encoded);
        rewind($handle);
        $decoded = Frame::decode($handle);
        fclose($handle);

        return $decoded;
    }
    /**
     * Simple text encode/decode
     *
     * @return void
     */
    public function testSimpleText(): void
    {
        $decoded = $this->encodeDecode('simple text');

        $this->assertTrue($decoded['opcode'] === Frame::TEXT);
        $this->assertTrue($decoded['payload'] === 'simple text');
    }

    /**
     * Short text encode/decode
     *
     * @return void
     */
    public function testShortText(): void
    {
        $decoded = $this->encodeDecode('');

        $this->assertTrue($decoded['opcode'] === Frame::TEXT);
        $this->assertTrue($decoded['payload'] === '');
    }

    /**
     * Long text encode/decode
     *
     * @return void
     */
    public function testLongText(): void
    {
        $text = 'very very long string';

        while (strlen($text) < 8192) {
            $text .= $text;
        }

        $decoded = $this->encodeDecode($text);

        $this->assertTrue($decoded['opcode'] === Frame::TEXT);
        $this->assertTrue($decoded['payload'] === $text);
    }

    /**
     * False positive socket
     *
     * @return void
     */
    public function testWrongSocket(): void {
        $decoded = Frame::decode(false);

        $this->assertArrayHasKey('opcode', $decoded);
        $this->assertEquals(Frame::CLOSE, $decoded['opcode']);
    }

    /**
     * Close message encode/decode
     *
     * @return void
     */
    public function testCloseSignal(): void
    {
        $decoded = $this->encodeDecode('', Frame::CLOSE);
        $this->assertTrue($decoded['opcode'] === Frame::CLOSE);
    }
}