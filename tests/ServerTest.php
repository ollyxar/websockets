<?php namespace WebSockets\Tests;

use PHPUnit\Framework\TestCase;
use Ollyxar\WebSockets\{
    Frame, Server, Exceptions\ForkException, Logger, Ssl
};

if (!class_exists(Handler::class)) {
    require_once 'Handler.php';
}

class ServerTest extends TestCase
{
    private $serverPid = 0;
    private const PORT = 2929;
    private const CERT = [
        'path'       => '/tmp/cert.pem',
        'passPhrase' => 'qwerty123'
    ];
    private $client;

    private function startServer(): void
    {
        Logger::enable();
        (new Server('0.0.0.0', static::PORT, 2, true))
            ->setHandler(Handler::class)
            ->setCert(static::CERT['path'])
            ->setPassPhrase(static::CERT['passPhrase'])
            ->run();
    }

    private function stopServer(): void
    {
        posix_kill($this->serverPid, SIGTERM);
    }

    /**
     * @throws ForkException
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

        $this->client = stream_socket_client('ssl://localhost:' . static::PORT, $errorNumber, $errorString, null, STREAM_CLIENT_CONNECT, $context);
    }

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
     * @throws ForkException
     */
    public function setUp()
    {
        Ssl::generateCert(static::CERT['path'], static::CERT['passPhrase']);
        $this->forkProcess();
        sleep(3);
        $this->makeClient();
    }

    public function __destruct()
    {
        $this->stopServer();
        unlink(static::CERT['path']);
    }

    public function testServer()
    {
        $key = $this->generateKey();

        $headers = [
            'host'                  => "localhost:" . static::PORT,
            'user-agent'            => 'websocket-client',
            'connection'            => 'Upgrade',
            'upgrade'               => 'websocket',
            'sec-websocket-key'     => $key,
            'sec-websocket-version' => '13'
        ];

        $header = "GET wss://localhost:" . static::PORT . " HTTP/1.1\r\n" . implode(
                "\r\n", array_map(
                    function ($key, $value) {
                        return "$key: $value";
                    }, array_keys($headers), $headers
                )
            ) . "\r\n\r\n";

        fwrite($this->client, $header);

        $this->assertTrue(true);
    }
}