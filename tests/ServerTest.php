<?php namespace WebSockets\Tests;

use PHPUnit\Framework\TestCase;
use Ollyxar\WebSockets\{
    Exceptions\ForkException,
    Exceptions\SocketException,
    Frame,
    Server,
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

    public static $serverStarted = false;

    /**
     * Starts Server
     *
     * @return void
     */
    private function startServer(): void
    {
        (new Server('0.0.0.0', static::PORT, 2, true))
            ->setHandler(Handler::class)
            ->setCert(static::CERT['path'])
            ->setPassPhrase(static::CERT['passPhrase'])
            ->run();
    }

    /**
     * Forking process to get properly working Server and separated test
     *
     * @throws ForkException
     * @return void
     */
    private function forkProcess(): void
    {
        if (static::$serverStarted) {
            return;
        }

        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new ForkException('Cannot fork process');
        } elseif ($pid) {
            $this->serverPid = $pid;
            sleep(3);
        } else {
            $this->startServer();
        }

        static::$serverStarted = true;
    }

    /**
     * Making lightweight WebSocket client
     *
     * @return resource
     */
    private function makeClient()
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

        usleep(100); // keep queue for more real case

        return stream_socket_client('ssl://localhost:' . static::PORT, $errorNumber, $errorString, null, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $context);
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
     * ServerTest constructor.
     *
     * @param null $name
     * @param array $data
     * @param string $dataName
     * @throws ForkException
     */
    public function __construct($name = null, array $data = [], $dataName = '')
    {
        Server::$connector = '/tmp/ws.sock';
        Ssl::generateCert(static::CERT['path'], static::CERT['passPhrase']);
        $this->forkProcess();

        parent::__construct($name, $data, $dataName);
    }

    /**
     * Simple messaging
     *
     * @throws SocketException
     */
    public function testBasicMessaging()
    {
        $client = $this->makeClient();

        if (!$client) {
            throw new SocketException('Client socket does not created properly');
        }

        fwrite($client, $this->generateHandshakeRequest());
        $response = fread($client, 4096);
        $this->assertContains('101 Web Socket Protocol Handshake', $response);

        $data = Frame::decode($client);
        $this->assertArrayHasKey('payload', $data);
        $this->assertContains('connected.', $data['payload']);

        fwrite($client, Frame::encode('client:hello'));
        $data = Frame::decode($client);
        $this->assertArrayHasKey('payload', $data);
        $this->assertEquals('client:hello', $data['payload']);

        fclose($client);
    }

    /**
     * Base connector test
     *
     * @throws SocketException
     */
    public function testConnector()
    {
        $client = $this->makeClient();

        if (!$client) {
            throw new SocketException('Client socket does not created properly');
        }

        fwrite($client, $this->generateHandshakeRequest());
        $response = fread($client, 4096);
        $this->assertContains('101 Web Socket Protocol Handshake', $response);

        $data = Frame::decode($client);
        $this->assertArrayHasKey('payload', $data);
        $this->assertContains('connected.', $data['payload']);

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_connect($socket, Server::$connector);
        socket_write($socket, Frame::encode('connector:hello'));
        socket_close($socket);

        $found = false;

        while (!$found) {
            $data = Frame::decode($client);
            $this->assertArrayHasKey('payload', $data);

            if ($data['payload'] == 'connector:hello') {
                $found = true;
            }
        }

        $this->assertTrue($found);

        fclose($client);
    }
}