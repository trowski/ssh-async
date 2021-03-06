<?php

namespace Amp\SSH\Channel;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\PendingReadError;
use function Amp\call;
use Amp\Deferred;
use Amp\Iterator;
use Amp\Promise;
use Amp\SSH\Message\ChannelData;
use Amp\SSH\Message\ChannelEof;
use Amp\SSH\Message\ChannelExtendedData;
use Amp\SSH\Message\Message;
use Amp\Success;

/**
 * @internal
 */
class ChannelInputStream implements InputStream
{
    private $readable = true;

    private $iterator;

    public function __construct(Iterator $iterator)
    {
        $this->iterator = $iterator;
    }

    /** {@inheritdoc} */
    public function read(): Promise
    {
        if (!$this->readable) {
            return new Success; // Resolve with null on closed stream.
        }

        return call(function () {
            $advanced = yield $this->iterator->advance();

            if (!$advanced) {
                $this->readable = false;

                return null;
            }

            $message = $this->iterator->getCurrent();

            if ($message instanceof ChannelData || $message instanceof ChannelExtendedData) {
                return $message->data;
            }

            return $message;
        });
    }
}