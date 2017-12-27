<?php

declare(strict_types=1);

namespace Amp\SSH;

use function Amp\call;
use Amp\Promise;
use Amp\Socket\Socket;
use Amp\SSH\Encryption;
use Amp\SSH\Mac;

class BinaryPacketHandler
{
    /** @var string */
    private $cryptedBuffer = '';

    /** @var string */
    private $decryptedBuffer = '';

    /** @var Socket */
    private $socket;

    /** @var Encryption\Encryption */
    private $encryption;

    /** @var Encryption\Decryption */
    private $decryption;

    /** @var Mac\Mac */
    private $encryptMac;

    /** @var Mac\Mac */
    private $decryptMac;

    /** @var int */
    private $writeSequenceNumber;

    /** @var int */
    private $readSequenceNumber;

    public function __construct(Socket $socket, string $buffer)
    {
        $this->socket = $socket;
        $this->cryptedBuffer = $buffer;
        $this->encryption = new Encryption\None();
        $this->decryption = new Encryption\None();
        $this->encryptMac = new Mac\None();
        $this->decryptMac = new Mac\None();
        $this->writeSequenceNumber = 0;
        $this->readSequenceNumber = 0;
    }

    /**
     * @param Encryption\Encryption $encryption
     */
    public function setEncryption(Encryption\Encryption $encryption): void
    {
        $this->encryption = $encryption;
    }

    /**
     * @param Encryption\Decryption $decryption
     */
    public function setDecryption(Encryption\Decryption $decryption): void
    {
        $this->decryption = $decryption;
    }

    /**
     * @param Mac\Mac $encryptMac
     */
    public function setEncryptMac(Mac\Mac $encryptMac): void
    {
        $this->encryptMac = $encryptMac;
    }

    /**
     * @param Mac\Mac $decryptMac
     */
    public function setDecryptMac(Mac\Mac $decryptMac): void
    {
        $this->decryptMac = $decryptMac;
    }

    public function read(): Promise
    {
        /*
        Each packet is in the following format:

          uint32    packet_length
          byte      padding_length
          byte[n1]  payload; n1 = packet_length - padding_length - 1
          byte[n2]  random padding; n2 = padding_length
          byte[m]   mac (Message Authentication Code - MAC); m = mac_length

          packet_length
             The length of the packet in bytes, not including 'mac' or the
             'packet_length' field itself.

          padding_length
             Length of 'random padding' (bytes).

          payload
             The useful contents of the packet.  If compression has been
             negotiated, this field is compressed.  Initially, compression
             MUST be "none".

          random padding
             Arbitrary-length padding, such that the total length of
             (packet_length || padding_length || payload || random padding)
             is a multiple of the cipher block size or 8, whichever is
             larger.  There MUST be at least four bytes of padding.  The
             padding SHOULD consist of random bytes.  The maximum amount of
             padding is 255 bytes.

          mac
             Message Authentication Code.  If message authentication has
             been negotiated, this field contains the MAC bytes.  Initially,
             the MAC algorithm MUST be "none".
         */
        return call(function () {
            $packetLength = unpack('N', yield $this->doRead(4))[1];
            $paddingLength = unpack('C', yield $this->doRead(1))[1];
            $payload = yield $this->doRead($packetLength - $paddingLength - 1);
            $padding = yield $this->doRead($paddingLength);
            $mac = yield $this->doRead($this->decryptMac->getLength());

            $computedMac = $this->decryptMac->hash(pack(
                'NNCa*',
                $this->readSequenceNumber,
                $packetLength,
                $paddingLength,
                $payload . $padding
            ));

            if (\sodium_compare($computedMac, $mac) !== 0) {
                throw new \RuntimeException('Invalid mac');
            }

            $this->readSequenceNumber++;

            return $payload;
        });
    }

    private function doRead(int $length, bool $decrypt = true): Promise
    {
        return call(function () use ($length, $decrypt) {
            while (\strlen($this->decryptedBuffer) < $length) {
                $this->cryptedBuffer .= yield $this->socket->read();
                $cryptedLength = \strlen($this->cryptedBuffer);
                $lengthToDecrypt = $cryptedLength - ($cryptedLength % $this->decryption->getBlockSize());

                if ($lengthToDecrypt > 0) {
                    $toDecrypt = substr($this->cryptedBuffer, 0, $lengthToDecrypt);
                    $this->cryptedBuffer = substr($this->cryptedBuffer, $lengthToDecrypt);
                    $this->decryptedBuffer .= $this->decryption->decrypt($toDecrypt);
                }
            }

            $read = substr($this->decryptedBuffer, 0, $length);
            $this->decryptedBuffer = substr($this->decryptedBuffer, $length);

            return $read;
        });
    }

    public function write($payload): Promise
    {
        // 4 + 1 + length + 4 (padding)
        $length = \strlen($payload) + 9;
        $length += (($this->encryption->getBlockSize() - 1) * $length) % $this->encryption->getBlockSize();
        $paddingLength = $length - \strlen($payload) - 5;
        $padding = \random_bytes($paddingLength);
        $packetLength = $length - 4;

        $packet = pack('NCa*', $packetLength, $paddingLength, $payload . $padding);
        $mac = $this->encryptMac->hash(pack('Na*', $this->writeSequenceNumber, $packet));
        $cipher = $this->encryption->crypt($packet);
        $cipher .= $mac;

        $this->writeSequenceNumber++;

        return $this->socket->write($cipher);
    }
}