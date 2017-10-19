# PHP WebSocket server

[![Build Status](https://travis-ci.org/ollyxar/websockets.svg?branch=master)](https://travis-ci.org/ollyxar/websockets)
![Version](https://poser.pugx.org/ollyxar/websockets/v/stable.svg)
![Downloads](https://poser.pugx.org/ollyxar/websockets/d/total.svg)
![License](https://poser.pugx.org/ollyxar/websockets/license.svg)

Simple and multifunctional PHP WebSocket server

#### Live chat example

![chat](https://i.imgur.com/7M9LhTD.jpg)

## Installing WebSockets

The recommended way to install WebSockets is through
[Composer](http://getcomposer.org).

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

```php
use Ollyxar\WebSockets\{
    Server as WServer,
    Ssl as Wsl,
    Worker as Handler,
    Frame as WFrame
};

class MyHandler extends Handler
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
     */
    protected function onConnect($client): void
    {
        $this->sendToAll(WFrame::encode(json_encode([
            'type'    => 'system',
            'message' => 'User {' . (int)$client . '} Connected.'
        ])));
    }

    /**
     * @param $clientNumber
     */
    protected function onClose($clientNumber): void
    {
        $this->sendToAll(WFrame::encode(json_encode([
            'type'    => 'system',
            'message' => "User {$clientNumber} disconnected."
        ])));
    }

    /**
     * @param string $message
     */
    protected function onDirectMessage(string $message): void
    {
        $message = json_decode($message);
        $userName = $message->name;
        $userMessage = $message->message;

        $response = WFrame::encode(json_encode([
            'type'    => 'usermsg',
            'name'    => $userName,
            'message' => $userMessage
        ]));

        $this->sendToAll($response);
    }
}

/**
 * Lets start our server
 */
(new WServer('0.0.0.0', 2083, 6, true))
    ->setCert()
    ->setPassPhrase()
    ->setHandler(MyHandler::class)
    ->run();
```

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
