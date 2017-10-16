<?php namespace Ollyxar\WebSockets;

use \Exception;

class Worker
{
    private $server;
    private $master;
    private $pid = 0;
    private $clients = [];

    public function __construct($server, $master)
    {
        $this->server = $server;
        $this->master = $master;
        $this->pid = posix_getpid();
    }

    private function sendMessage(string $msg): void
    {
        foreach ($this->clients as $client) {
            @fwrite($client, $msg);
        }
    }

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
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function handle(): void
    {
        while (true) {
            $read = $this->clients;
            $read[] = $this->server;
            $read[] = $this->master;
            $write = [];

            @stream_select($read, $write, $except, null);

            /** New user connected */
            if (in_array($this->server, $read)) {
                if ($client = @stream_socket_accept($this->server)) {
                    $this->clients[(int)$client] = $client;
                    if (!$this->handshake($client)) {
                        unset($this->clients[(int)$client]);
                        fclose($client);
                    } else {
                        fwrite($client, Frame::encode(json_encode([
                            'type'    => 'system',
                            'message' => 'Connected.'
                        ])));
                    }
                }

                unset($read[array_search($this->server, $read)]);
            }

            /** Message from master */
            if (in_array($this->master, $read)) {
                $data = Frame::decode($this->master);

                if ($data['opcode'] == Frame::TEXT) {
                    $this->sendMessage(Frame::encode($data['payload']));
                }

                unset($read[array_search($this->master, $read)]);
            }

            /** Message from user */
            foreach ($read as $changedSocket) {
                $data = Frame::decode($changedSocket);

                if ($data['opcode'] == Frame::CLOSE) {
                    $socketPosition = array_search($changedSocket, $this->clients);
                    unset($this->clients[$socketPosition]);
                }

                if ($data['opcode'] == Frame::TEXT) {
                    $receivedText = $data['payload'];
                    $message = json_decode($receivedText);
                    $userName = $message->name;
                    $userMessage = $message->message;

                    $response = Frame::encode(json_encode([
                        'type'    => 'usermsg',
                        'name'    => $userName,
                        'message' => $userMessage
                    ]));

                    $this->sendMessage($response);
                    fwrite($this->master, $response);
                }
                break;
            }
        }
    }
}