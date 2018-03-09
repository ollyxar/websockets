# PHP WebSocket server

[![Build Status](https://travis-ci.org/ollyxar/websockets.svg?branch=master)](https://travis-ci.org/ollyxar/websockets)
![Version](https://poser.pugx.org/ollyxar/websockets/v/stable.svg)
![Downloads](https://poser.pugx.org/ollyxar/websockets/d/total.svg)
![License](https://poser.pugx.org/ollyxar/websockets/license.svg)

Simple and multifunctional PHP WebSocket server

![ollyxar websockets](https://ollyxar.com/img/blog/ows.png)

#### Live chat example

![chat](https://i.imgur.com/7M9LhTD.jpg)

#### Performance
![ollyxar websockets performance](https://ollyxar.com/img/blog/wss.png)

## Installing WebSockets

The recommended way to install WebSockets is through [Composer](http://getcomposer.org).

```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
```

Next, run the Composer command to install the latest stable version of WebSockets:

```bash
php composer.phar require ollyxar/websockets
```

After installing, you need to require Composer's autoloader:

```php
require 'vendor/autoload.php';
```

## Simple WebSocket server

### MyHandler.php

```php
use Generator;
use Ollyxar\LaravelAuth\FileAuth;
use Ollyxar\WebSockets\{
    Frame,
    Handler as BaseHandler,
    Dispatcher
};

class MyHandler extends BaseHandler
{
    /**
     * MyHandler constructor.
     * @param $server
     * @param $master
     */
    public function __construct($server, $master)
    {
        parent::__construct($server, $master);
        echo "I'm: #{$this->pid}\n";
    }

    /**
     * @param $client
     * @return Generator
     */
    protected function onConnect($client): Generator
    {
        yield Dispatcher::async($this->broadcast(Frame::encode(json_encode([
            'type'    => 'system',
            'message' => 'User {' . (int)$client . '} Connected.'
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
            'message' => "User {$clientNumber} disconnected."
        ]))));
    }

    /**
     * @param string $message
     * @param int $socketId
     * @return Generator
     */
    protected function onClientMessage(string $message, int $socketId): Generator
    {
        $message = json_decode($message);
        $userName = $message->name;
        $userMessage = $message->message;

        $response = Frame::encode(json_encode([
            'type'    => 'usermsg',
            'name'    => $userName,
            'message' => $userMessage
        ]));

        yield Dispatcher::async($this->sendToAll($response));
    }
}
```

### User validation

Base `Handler` has own method to validate user to be logged in. By default it is always return true. You should provide your own implementation of method to authorize users.

```php
    /**
     * Use this method for custom user validation
     *
     * @param array $headers
     * @param $socket
     * @return bool
     */
    protected function validateClient(array $headers, $socket): bool
    {
        return true;
    }
```

### ws-server.php

```php
/**
 * Lets start our server
 */
(new Server('0.0.0.0', 2083, 6, true))
    ->setCert()
    ->setPassPhrase()
    ->setHandler(MyHandler::class)
    ->run();
```


### Realtime testing (logging)

The server has internal logger that can output info into console.
All that you need is just to enable logging before launching server.

```php
Logger::enable();
```

![output logging](https://i.imgur.com/HaukgbL.jpg)

### Communicate with server outside the wss protocol

```php
use Ollyxar\WebSockets\Server as WServer;
use Ollyxar\WebSockets\Frame;

$socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
socket_connect($socket, WServer::$connector);

$data = new stdClass();
$data->type = 'system';
$data->message = 'hello World!';
$msg = Frame::encode(json_encode($data));

socket_write($socket, $msg);
socket_close($socket);
```
