<?php namespace Ollyxar\WebSockets;

use Exception;
use Generator;

/**
 * Class Worker
 * @package Ollyxar\WebSockets
 */
abstract class Handler
{
    protected $server;
    protected $master;
    protected $pid = 0;
    protected $clients = [];

    /**
     * Worker constructor.
     *
     * @param $server
     * @param $master
     */
    public function __construct($server, $master)
    {
        $this->server = $server;
        $this->master = $master;
        $this->pid = posix_getpid();
    }

    /**
     * Sending message to all connected users
     *
     * @param string $msg
     * @param bool $global
     * @return Generator
     */
    protected function sendToAll(string $msg, bool $global = true): Generator
    {
        Logger::log('worker', $this->pid, 'send to all: ', $msg);

        foreach ($this->clients as $client) {
            yield Dispatcher::async($this->write($client, $msg));
        }

        if ($global) {
            yield Dispatcher::async($this->write($this->master, $msg));
        }
    }

    /**
     * Performing handshake
     *
     * @param $socket
     * @return Generator
     */
    private function handshake($socket): Generator
    {
        yield Dispatcher::listenRead($socket);
        Logger::log('worker', $this->pid, 'handshake for ', (int)$socket);
        $headers = [];
        $lines = preg_split("/\r\n/", @fread($socket, 4096));

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        if (!isset($headers['Sec-WebSocket-Key'])) {
            return yield;
        }

        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $response = "HTTP/1.1 101 Web Socket Protocol Handshake\n" .
            "Upgrade: websocket\n" .
            "Connection: Upgrade\n" .
            "Sec-WebSocket-Accept:$secAccept\n\n";

        Logger::log('worker', $this->pid, 'handshake for ' . (int)$socket . " done.");

        try {
            yield Dispatcher::async($this->write($socket, $response));
            yield Dispatcher::async($this->afterHandshake($headers, $socket));
        } catch (Exception $e) {
            return yield;
        }
    }

    /**
     * @param $client
     * @return Generator
     */
    protected function read($client): Generator
    {
        yield Dispatcher::listenRead($client);

        $data = Frame::decode($client);

        switch ($data['opcode']) {
            case Frame::CLOSE:
                Logger::log('worker', $this->pid, 'close', (int)$client);
                yield Dispatcher::async($this->onClose((int)$client));
                yield Dispatcher::listenRemove((int)$client);
                unset($this->clients[(int)$client]);
                fclose($client);
                break;
            case Frame::PING:
                Logger::log('worker', $this->pid, 'ping', (int)$client);
                yield Dispatcher::async($this->write($client, Frame::encode($data['payload'], Frame::PONG)));
                break;
            case Frame::TEXT:
                Logger::log('worker', $this->pid, 'text from', (int)$client);
                yield Dispatcher::async($this->onClientMessage($data['payload'], (int)$client));
                break;
        }

        yield Dispatcher::async($this->read($client));
    }

    /**
     * @param $client
     * @param $data
     * @return Generator
     */
    protected function write($client, $data): Generator
    {
        yield Dispatcher::listenWrite($client);
        Logger::log('worker', $this->pid, 'fwrite to', (int)$client . ' - ' . (string)$data);
        @fwrite($client, $data);
    }

    /**
     * @return Generator
     */
    protected function listerMaster(): Generator
    {
        yield Dispatcher::listenRead($this->master);
        yield Dispatcher::listenWrite($this->master);
        $data = Frame::decode($this->master);

        if ($data['opcode'] == Frame::TEXT) {
            Logger::log('worker', $this->pid, 'received text from master:', $data['payload']);
            yield Dispatcher::async($this->onMasterMessage($data['payload']));
        }
        yield Dispatcher::async($this->listerMaster());
    }

    /**
     * Main socket listener
     *
     * @return Generator
     */
    protected function listenSocket(): Generator
    {
        yield Dispatcher::listenRead($this->server);

        if ($client = @stream_socket_accept($this->server)) {
            Logger::log('worker', $this->pid, 'socket accepted');
            yield Dispatcher::async($this->handshake($client));
        }

        yield Dispatcher::async($this->listenSocket());
    }

    protected function validateClient(array $headers, $socket): bool
    {
        return true;
    }

    /**
     * Process headers after handshake success
     *
     * @param array $headers
     * @param $socket
     * @return Generator
     */
    private function afterHandshake(array $headers, $socket): Generator
    {
        if (!$this->validateClient($headers, $socket)) {
            Logger::log('worker', $this->pid, 'handshake for ' . (int)$socket . ' aborted');
            unset($this->clients[(int)$socket]);
            yield Dispatcher::listenRemove((int)$socket);
            fclose($socket);
        } else {
            Logger::log('worker', $this->pid, 'connection accepted for', (int)$socket);
            $this->clients[(int)$socket] = $socket;

            yield Dispatcher::async($this->onConnect($socket));
            yield Dispatcher::async($this->read($socket));
        }
    }

    /**
     * Called when user successfully connected
     *
     * @param $client
     * @return Generator
     */
    abstract protected function onConnect($client): Generator;

    /**
     * Called when user disconnected gracefully
     *
     * @param $clientNumber
     * @return Generator
     */
    abstract protected function onClose($clientNumber): Generator;

    /**
     * This method called when user directly (from the browser) send a message
     *
     * @param string $message
     * @param int $socketId
     * @return Generator
     */
    protected function onClientMessage(string $message, int $socketId): Generator
    {
        yield Dispatcher::async($this->sendToAll(Frame::encode($message)));
    }

    /**
     * This method called when message received from the Master.
     *
     * @param string $message
     * @return Generator
     */
    protected function onMasterMessage(string $message): Generator
    {
        yield Dispatcher::async($this->sendToAll(Frame::encode($message), false));
    }

    /**
     * Handle connections
     *
     * @return void
     */
    final public function handle(): void
    {
        (new Dispatcher())
            ->add($this->listerMaster())
            ->add($this->listenSocket())
            ->dispatch();
    }
}
