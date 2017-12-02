<?php namespace Ollyxar\WebSockets;

use Generator;

/**
 * Class Master
 * @package Ollyxar\WebSockets
 */
final class Master
{
    /**
     * Socket handler
     */
    private $handler;

    /**
     * Unix socket connector
     */
    private $connector;

    /**
     * Master constructor.
     *
     * @param $handler
     * @param $connector
     */
    public function __construct($handler, $connector)
    {
        $this->handler = $handler;
        $this->connector = $connector;
    }

    /**
     * @param $client
     * @param $data
     * @return Generator
     */
    protected function write($client, $data): Generator
    {
        Logger::log('master', posix_getpid(), 'write to ' . (int)$client, $data);

        yield Dispatcher::listenWrite($client);
        fwrite($client, $data);
    }

    /**
     * @return Generator
     */
    protected function listenConnector(): Generator
    {
        yield Dispatcher::listenRead($this->connector);

        if ($socket = @stream_socket_accept($this->connector)) {
            Logger::log('master', posix_getpid(), 'connector accepted');
            $data = Frame::decode($socket);

            if (!$data['opcode']) {
                return yield;
            }

            yield Dispatcher::listenWrite($this->handler);
            yield Dispatcher::async($this->write($this->handler, Frame::encode($data['payload'], $data['opcode'])));
        }

        yield Dispatcher::async($this->listenConnector());
    }

    /**
     * Dispatch messaging
     *
     * @return void
     */
    public function dispatch(): void
    {
        (new Dispatcher())
            ->add($this->listenConnector())
            ->dispatch();
    }
}
