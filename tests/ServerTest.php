<?php namespace WebSockets\Tests;

use PHPUnit\Framework\TestCase;
use Ollyxar\WebSockets\{
    Frame,
    Server,
    Exceptions\ForkException,
    Logger,
    Ssl
};

if (!class_exists(Handler::class)) {
    require_once 'Handler.php';
}

/**
 * Class ServerTest
 * @package WebSockets\Tests
 */
class ServerTest extends TestCase
{
    private $serverPid = 0;
    private const PORT = 2929;
    private const CERT = [
        'path'       => '/tmp/cert.pem',
        'passPhrase' => 'qwerty123'
    ];
    private $client;

    /**
     * Starts Server
     *
     * @return void
     */
    private function startServer(): void
    {
        Logger::enable();
        Server::$connector = '/tmp/ws.sock';

        (new Server('0.0.0.0', static::PORT, 2, true))
            ->setHandler(Handler::class)
            ->setCert(static::CERT['path'])
            ->setPassPhrase(static::CERT['passPhrase'])
            ->run();
    }

    /**
     * Stops server
     *
     * @return void
     */
    private function stopServer(): void
    {
        posix_kill($this->serverPid, SIGTERM);
    }

    /**
     * Forking process to get properly working Server and separated test
     *
     * @throws ForkException
     * @return void
     */
    private function forkProcess(): void
    {
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new ForkException('Cannot fork process');
        } elseif ($pid) {
            $this->serverPid = $pid;
        } else {
            $this->startServer();
        }
    }

    /**
     * Making lightweight WebSocket client
     *
     * @return void
     */
    private function makeClient(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'local_cert'        => static::CERT['path'],
                'passphrase'        => static::CERT['passPhrase'],
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'verify_depth'      => 0
            ]
        ]);

        $this->client = stream_socket_client('ssl://localhost:' . static::PORT, $errorNumber, $errorString, null, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $context);
    }

    /**
     * @return string
     */
    private function generateKey(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';
        $key = '';
        $chars_length = strlen($chars);

        for ($i = 0; $i < 16; $i++) {
            $key .= $chars[mt_rand(0, $chars_length - 1)];
        }

        return base64_encode($key);
    }

    /**
     * @return string
     */
    private function generateHandshakeRequest(): string
    {
        $key = $this->generateKey();

        $headers = [
            'Host'                  => 'localhost:' . static::PORT,
            'User-Agent'            => 'websocket-client',
            'Connection'            => 'Upgrade',
            'Upgrade'               => 'websocket',
            'Sec-WebSocket-Key'     => $key,
            'Sec-WebSocket-Version' => '13'
        ];

        $result = "GET wss://localhost:" . static::PORT . "/ HTTP/1.1\r\n";

        foreach ($headers as $header => $value) {
            $result .= "$header: $value\r\n";
        }

        return $result . "\r\n\r\n";
    }

    /**
     * @throws ForkException
     */
    public function setUp()
    {
        Ssl::generateCert(static::CERT['path'], static::CERT['passPhrase']);
        $this->forkProcess();
        sleep(3);
        $this->makeClient();
    }

    /**
     * Free resources
     */
    public function __destruct()
    {
        $this->stopServer();
        @unlink(static::CERT['path']);
    }

    /**
     * Simple messaging
     */
    public function testBasicMessaging()
    {
        fwrite($this->client, $this->generateHandshakeRequest());
        $response = fread($this->client, 4096);
        $this->assertContains('101 Web Socket Protocol Handshake', $response);

        $data = Frame::decode($this->client);
        $this->assertArrayHasKey('payload', $data);
        $this->assertContains('connected.', $data['payload']);

        fwrite($this->client, Frame::encode('Hello message'));
        $data = Frame::decode($this->client);
        $this->assertArrayHasKey('payload', $data);
        $this->assertEquals('Hello message', $data['payload']);
    }
}