<?php

namespace Amp\SSH;

use function Amp\asyncCall;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use function Amp\call;
use Amp\Deferred;
use Amp\Promise;
use Amp\SSH\Channel\ChannelInputStream;
use Amp\SSH\Channel\ChannelOutputStream;
use Amp\SSH\Message\ChannelRequestExitStatus;

class Shell
{
    /** @var Channel\Session */
    private $session;

    /** @var ChannelInputStream */
    private $stderr;

    /** @var ChannelInputStream */
    private $stdout;

    /** @var ChannelOutputStream */
    private $stdin;

    /** @var Deferred */
    private $resolved;

    /** @var array */
    private $env;

    public function __construct(SSHResource $sshResource, array $env = [])
    {
        $this->session = $sshResource->createSession();
        $this->stdout = new ChannelInputStream($this->session->getDataEmitter()->iterate());
        $this->stderr = new ChannelInputStream($this->session->getDataExtendedEmitter()->iterate());
        $this->stdin = new ChannelOutputStream($this->session);
        $this->env = $env;
    }

    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->kill();
        }
    }

    public function join(): Promise
    {
        if (!$this->isRunning()) {
            return new Failure(new \RuntimeException('Process is not running'));
        }

        return $this->resolved->promise();
    }

    public function start(): Promise
    {
        if ($this->isRunning()) {
            return new Failure(new \RuntimeException('Process has already been started.'));
        }

        $this->resolved = new Deferred();

        return call(function () {
            yield $this->session->open();

            foreach ($this->env as $key => $value) {
                yield $this->session->env($key, $value, true);
            }

            yield $this->session->pty();
            yield $this->session->shell();

            $this->handleRequests();
        });
    }

    public function kill(): void
    {
        if (!$this->isRunning()) {
            throw new \RuntimeException('Process is not running.');
        }

        Promise\rethrow($this->signal(SIGKILL));
    }

    public function signal(int $signo): Promise
    {
        if (!$this->isRunning()) {
            return new Failure(new \RuntimeException('Process is not running.'));
        }

        return $this->session->signal($signo);
    }

    public function isRunning()
    {
        return $this->resolved !== null;
    }

    public function getStdin(): OutputStream
    {
        return $this->stdin;
    }

    public function getStdout(): InputStream
    {
        return $this->stdout;
    }

    public function getStderr(): InputStream
    {
        return $this->stderr;
    }

    protected function handleRequests(): void
    {
        asyncCall(function () {
            $requestIterator = $this->session->getRequestEmitter()->iterate();

            while ($this->isRunning()) {
                yield $requestIterator->advance();
                $message = $requestIterator->getCurrent();

                if ($message instanceof ChannelRequestExitStatus) {
                    $resolved = $this->resolved;
                    $this->resolved = null;
                    $resolved->resolve($message->code);

                    $this->session->close();

                    break;
                }
            }
        });
    }
}