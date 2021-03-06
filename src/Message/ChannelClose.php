<?php

declare(strict_types=1);

namespace Amp\SSH\Message;

use function Amp\SSH\Transport\read_byte;
use function Amp\SSH\Transport\read_uint32;

class ChannelClose implements Message
{
    public $recipientChannel;

    public function encode(): string
    {
        return pack(
            'CN',
            self::getNumber(),
            $this->recipientChannel
        );
    }

    public static function decode(string $payload)
    {
        read_byte($payload);

        $message = new static;
        $message->recipientChannel = read_uint32($payload);

        return $message;
    }

    public static function getNumber(): int
    {
        return self::SSH_MSG_CHANNEL_CLOSE;
    }
}
