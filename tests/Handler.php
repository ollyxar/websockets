<?php namespace WebSockets\Tests;

use Generator;
use Ollyxar\WebSockets\{
    Dispatcher,
    Frame,
    Handler as BaseHandler,
    Logger
};

class Handler extends BaseHandler
{
    /**
     * Handler constructor.
     * @param $server
     * @param $master
     */
    public function __construct($server, $master)
    {
        parent::__construct($server, $master);
        Logger::log('worker', $this->pid, 'Worker created.');
    }

    /**
     * @param $client
     * @return Generator
     */
    protected function onConnect($client): Generator
    {
        yield Dispatcher::async($this->broadcast(Frame::encode(json_encode([
            'type'    => 'system',
            'message' => (int)$client . ' connected.'
        ]))));
    }

    /**
     * @param $clientNumber
     * @return Generator
     */
    protected function onClose($clientNumber): Generator
    {
        yield Dispatcher::async($this->broadcast(Frame::encode(json_encode([
            'type'    => 'system',
            'message' => $clientNumber . ' disconnected.'
        ]))));
    }
}