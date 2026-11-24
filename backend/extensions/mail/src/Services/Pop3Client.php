<?php

namespace App\Extensions\Mail\Services;

use RuntimeException;

/**
 * Minimal POP3 client over a raw socket. Downloads the most recent messages
 * so they can be stored in the local mailbox.
 */
class Pop3Client
{
    private $stream = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private string $username,
        private string $password,
        private int $timeout = 20,
        private bool $verifyPeer = false,
    ) {
    }

    public function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
            ],
        ]);

        $stream = @stream_socket_client(
            "{$scheme}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw new RuntimeException("Не удалось подключиться к POP3 {$this->host}:{$this->port} — {$errstr}");
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, $this->timeout);

        if (!str_starts_with($this->readLine(), '+OK')) {
            throw new RuntimeException('Некорректный ответ POP3-сервера');
        }

        if ($this->encryption === 'tls') {
            $this->send('STLS');
            if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Не удалось установить TLS-соединение (STLS)');
            }
        }

        $this->send('USER ' . $this->username);
        $this->send('PASS ' . $this->password);
    }

    public function count(): int
    {
        $line = $this->send('STAT');
        $parts = preg_split('/\s+/', trim($line)) ?: [];

        return isset($parts[1]) ? (int) $parts[1] : 0;
    }

    /**
     * @return array<int, array{index: int, size: int}>
     */
    public function list(): array
    {
        $response = $this->sendMulti('LIST');
        $items = [];
        foreach ($response as $line) {
            if (preg_match('/^(\d+)\s+(\d+)$/', trim($line), $m)) {
                $items[] = ['index' => (int) $m[1], 'size' => (int) $m[2]];
            }
        }

        return $items;
    }

    public function retrieve(int $index): string
    {
        return implode("\r\n", $this->sendMulti('RETR ' . $index));
    }

    /**
     * Mark a message for deletion. POP3 commits deletions on QUIT.
     */
    public function deleteMessage(int $index): void
    {
        $this->send('DELE ' . $index);
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }

        try {
            $this->send('QUIT');
        } catch (\Throwable) {
            // ignore
        }
        @fclose($this->stream);
        $this->stream = null;
    }

    private function send(string $command): string
    {
        if ($this->stream === null) {
            throw new RuntimeException('POP3-соединение не установлено');
        }
        @fwrite($this->stream, $command . "\r\n");
        $line = $this->readLine();
        if (!str_starts_with($line, '+OK')) {
            throw new RuntimeException('POP3: ' . trim(substr($line, 3)));
        }

        return $line;
    }

    /**
     * @return array<int, string>
     */
    private function sendMulti(string $command): array
    {
        if ($this->stream === null) {
            throw new RuntimeException('POP3-соединение не установлено');
        }
        @fwrite($this->stream, $command . "\r\n");
        $first = $this->readLine();
        if (!str_starts_with($first, '+OK')) {
            throw new RuntimeException('POP3: ' . trim(substr($first, 3)));
        }

        $lines = [];
        while (true) {
            $line = $this->readLine();
            if ($line === '.' || $line === null) {
                break;
            }
            // Byte-stuffing: leading dots are doubled by the server.
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private function readLine(): string
    {
        if ($this->stream === null) {
            throw new RuntimeException('POP3-соединение не установлено');
        }
        $line = fgets($this->stream);
        if ($line === false) {
            throw new RuntimeException('Соединение с POP3-сервером прервано');
        }

        return rtrim($line, "\r\n");
    }
}
