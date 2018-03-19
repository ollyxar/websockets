<?php namespace WebSockets\Tests;

use Generator;
use Ollyxar\WebSockets\{
    Frame,
    Handler as BaseHandler,
    Dispatcher,
    Logger
};

class Handler extends BaseHandler
{

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

    protected function onClose($clientNumber): Generator
    {
        yield Dispatcher::async($this->broadcast(Frame::encode(json_encode([
            'type'    => 'system',
            'message' => $clientNumber . ' disconnected.'
        ]))));
    }
}