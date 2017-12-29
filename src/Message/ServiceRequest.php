<?php

declare(strict_types=1);

namespace Amp\SSH\Message;

class ServiceRequest implements Message
{
    public $serviceName;

    public function encode(): string
    {
        return pack(
            'CNa*',
            self::getNumber(),
            \strlen($this->serviceName),
            $this->serviceName
        );
    }

    public static function decode(string $payload)
    {
        return new static();
    }

    public static function getNumber(): int
    {
        return self::SSH_MSG_SERVICE_REQUEST;
    }
}
