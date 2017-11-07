<?php namespace Ollyxar\WebSockets;

/**
 * Class Frame
 * @package Ollyxar\WebSockets
 */
class Frame
{
    public const TEXT = 0x1;
    public const BINARY = 0x2;
    public const CLOSE = 0x8;
    public const PING = 0x9;
    public const PONG = 0xa;

    /**
     * @param $message
     * @param int $opCode
     * @return string
     */
    public static function encode($message, $opCode = Frame::TEXT): string
    {
        $rsv1 = 0x0;
        $rsv2 = 0x0;
        $rsv3 = 0x0;

        $length = strlen($message);

        $out = chr((0x1 << 7) | ($rsv1 << 6) | ($rsv2 << 5) | ($rsv3 << 4) | $opCode);

        if (0xffff < $length) {
            $out .= chr(0x7f) . pack('NN', 0, $length);
        } elseif (0x7d < $length) {
            $out .= chr(0x7e) . pack('n', $length);
        } else {
            $out .= chr($length);
        }

        return $out . $message;
    }

    /**
     * @param $socket
     * @return array
     */
    public static function decode($socket): array
    {
        if (!$socket || !is_resource($socket)) {
            return [
                'opcode'  => static::CLOSE,
                'payload' => ''
            ];
        }

        $out = [];
        $read = @fread($socket, 1);

        if (empty($read)) {
            return [
                'opcode'  => static::CLOSE,
                'payload' => ''
            ];
        }

        $handle = ord($read);
        $out['fin'] = ($handle >> 7) & 0x1;
        $out['rsv1'] = ($handle >> 6) & 0x1;
        $out['rsv2'] = ($handle >> 5) & 0x1;
        $out['rsv3'] = ($handle >> 4) & 0x1;
        $out['opcode'] = $handle & 0xf;

        if (!in_array($out['opcode'], [
            static::TEXT,
            static::BINARY,
            static::CLOSE,
            static::PING,
            static::PONG,
        ])) {
            return [
                'opcode'  => '',
                'payload' => '',
                'error'   => 'unknown opcode (1003)'
            ];
        }

        $handle = ord(fread($socket, 1));
        $out['mask'] = ($handle >> 7) & 0x1;
        $out['length'] = $handle & 0x7f;
        $length = &$out['length'];

        if ($out['rsv1'] !== 0x0 || $out['rsv2'] !== 0x0 || $out['rsv3'] !== 0x0) {
            return [
                'opcode'  => $out['opcode'],
                'payload' => '',
                'error'   => 'protocol error (1002)'
            ];
        }

        if ($length === 0) {
            $out['payload'] = '';
            return $out;
        } elseif ($length === 0x7e) {
            $handle = unpack('nl', fread($socket, 2));
            $length = $handle['l'];
        } elseif ($length === 0x7f) {
            $handle = unpack('N*l', fread($socket, 8));
            $length = isset($handle['l2']) ? $handle['l2'] : $length;

            if ($length > 0x7fffffffffffffff) {
                return [
                    'opcode'  => $out['opcode'],
                    'payload' => '',
                    'error'   => 'content length mismatch'
                ];
            }
        }

        if ($out['mask'] === 0x0) {
            $msg = '';
            $readLength = 0;

            while ($readLength < $length) {
                $toRead = $length - $readLength;
                $msg .= fread($socket, $toRead);

                if ($readLength === strlen($msg)) {
                    break;
                }

                $readLength = strlen($msg);
            }

            $out['payload'] = $msg;
            return $out;
        }

        $maskN = array_map('ord', str_split(fread($socket, 4)));
        $maskC = 0;

        $bufferLength = 1024;
        $message = '';

        for ($i = 0; $i < $length; $i += $bufferLength) {
            $buffer = min($bufferLength, $length - $i);
            $handle = fread($socket, $buffer);

            for ($j = 0, $_length = strlen($handle); $j < $_length; ++$j) {
                $handle[$j] = chr(ord($handle[$j]) ^ $maskN[$maskC]);
                $maskC = ($maskC + 1) % 4;
            }

            $message .= $handle;
        }

        $out['payload'] = $message;
        return $out;
    }
}
