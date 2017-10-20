<?php namespace Ollyxar\WebSockets;

use \Exception;

/**
 * Class Worker
 * @package Ollyxar\WebSockets
 */
abstract class Worker
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
     * @return void
     */
    protected function sendToAll(string $msg, bool $global = true): void
    {
        foreach ($this->clients as $client) {
            @fwrite($client, $msg);
        }

        if ($global) {
            fwrite($this->master, $msg);
        }
    }

    /**
     * Performing handshake
     *
     * @param $socket
     * @return bool
     */
    private function handshake($socket): bool
    {
        $headers = [];
        $lines = preg_split("/\r\n/", @fread($socket, 4096));

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        if (!isset($headers['Sec-WebSocket-Key'])) {
            return false;
        }

        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $response = "HTTP/1.1 101 Web Socket Protocol Handshake\n" .
            "Upgrade: websocket\n" .
            "Connection: Upgrade\n" .
            "Sec-WebSocket-Accept:$secAccept\n\n";

        try {
            fwrite($socket, $response);
            return $this->afterHandshake($headers);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Process headers after handshake success
     *
     * @param array $headers
     * @return bool
     */
    protected function afterHandshake(array $headers): bool
    {
        return true;
    }
    
    /**
     * Called when user successfully connected
     *
     * @param $client
     * @return void
     */
    abstract protected function onConnect($client): void;

    /**
     * Called when user disconnected gracefully
     *
     * @param $clientNumber
     * @return void
     */
    abstract protected function onClose($clientNumber): void;

    /**
     * This method called when user directly (from the browser) send a message
     * Attention! Current method will retransmit data to the master. Because Master dispatching all messages
     * and routing them to other processes. If you don't want to resend the data - overwrite this method
     *
     * @param string $message
     * @return void
     */
    protected function onDirectMessage(string $message): void
    {
        $this->sendToAll(Frame::encode($message));
    }

    /**
     * This method called when message received from the Master. It can be retransmitted message or message
     * from Unix connector
     *
     * @param string $message
     * @return void
     */
    protected function onFilteredMessage(string $message):void
    {
        $this->sendToAll(Frame::encode($message), false);
    }

    /**
     * Handle connections
     *
     * @return void
     */
    public function handle(): void
    {
        while (true) {
            $read = $this->clients;
            $read[] = $this->server;
            $read[] = $this->master;
            $write = [];

            @stream_select($read, $write, $except, null);

            if (in_array($this->server, $read)) {
                if ($client = @stream_socket_accept($this->server)) {
                    $this->clients[(int)$client] = $client;
                    if (!$this->handshake($client)) {
                        unset($this->clients[(int)$client]);
                        fclose($client);
                    } else {
                        $this->onConnect($client);
                    }
                }

                unset($read[array_search($this->server, $read)]);
            }

            if (in_array($this->master, $read)) {
                $data = Frame::decode($this->master);

                if ($data['opcode'] == Frame::TEXT) {
                    $this->onFilteredMessage($data['payload']);
                }

                unset($read[array_search($this->master, $read)]);
            }

            foreach ($read as $changedSocket) {
                $data = Frame::decode($changedSocket);

                if ($data['opcode'] == Frame::CLOSE) {
                    $socketPosition = array_search($changedSocket, $this->clients);
                    $this->onClose($socketPosition);
                    unset($this->clients[$socketPosition]);
                }

                if ($data['opcode'] == Frame::TEXT) {
                    $this->onDirectMessage($data['payload']);
                }
                break;
            }
        }
    }
}
