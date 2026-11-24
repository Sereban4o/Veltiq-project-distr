<?php

namespace App\Extensions\Mail\Services;

use RuntimeException;

/**
 * Minimal IMAP client over a raw socket (the php-imap extension is not
 * available in this project). Supports the subset needed to sync a mailbox:
 * LOGIN, SELECT, UID SEARCH and UID FETCH of full messages.
 */
class ImapClient
{
    private $stream = null;

    private int $tag = 0;

    /** @var array<int, string> */
    private array $literals = [];

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
            throw new RuntimeException("Не удалось подключиться к IMAP {$this->host}:{$this->port} — {$errstr}");
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, $this->timeout);

        $greeting = $this->readLine();
        if ($greeting === null || !str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('Некорректный ответ IMAP-сервера');
        }

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Не удалось установить TLS-соединение (STARTTLS)');
            }
        }

        $this->login();
    }

    private function login(): void
    {
        $this->command('LOGIN ' . $this->quote($this->username) . ' ' . $this->quote($this->password));
    }

    public function selectInbox(): void
    {
        $this->command('SELECT "INBOX"');
    }

    /**
     * @return array<int, string> message UIDs, ascending
     */
    public function searchAll(): array
    {
        $lines = $this->command('UID SEARCH ALL');
        $uids = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $id) {
                    if ($id !== '') {
                        $uids[] = $id;
                    }
                }
            }
        }

        return $uids;
    }

    public function fetchRaw(string $uid): ?string
    {
        $this->literals = [];
        $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');

        return $this->literals[0] ?? null;
    }

    /**
     * Permanently remove a message from the server.
     *
     * Marks the message as \Deleted and expunges it. Uses UID EXPUNGE when the
     * server supports it, otherwise falls back to a plain EXPUNGE.
     */
    public function deleteMessage(string $uid): void
    {
        $this->deleteMessages([$uid]);
    }

    /**
     * Permanently remove several messages in one round trip.
     *
     * @param  array<int, string|int>  $uids
     */
    public function deleteMessages(array $uids): void
    {
        $uids = array_values(array_unique(array_filter(
            array_map(static fn ($uid) => (int) $uid, $uids),
            static fn (int $uid) => $uid > 0,
        )));

        if ($uids === []) {
            return;
        }

        sort($uids);
        $set = implode(',', $uids);

        $this->command('UID STORE ' . $set . ' +FLAGS.SILENT (\\Deleted)');

        try {
            $this->command('UID EXPUNGE ' . $set);
        } catch (RuntimeException) {
            // UIDPLUS is not supported by this server.
            $this->command('EXPUNGE');
        }
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }

        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // ignore
        }
        @fclose($this->stream);
        $this->stream = null;
    }

    /**
     * @return array<int, string>
     */
    private function command(string $command): array
    {
        $tag = 'A' . (++$this->tag);
        $this->write($tag . ' ' . $command . "\r\n");

        return $this->readUntilTagged($tag);
    }

    private function write(string $data): void
    {
        if ($this->stream === null) {
            throw new RuntimeException('IMAP-соединение не установлено');
        }
        @fwrite($this->stream, $data);
    }

    /**
     * @return array<int, string>
     */
    private function readUntilTagged(string $tag): array
    {
        $lines = [];
        while (true) {
            $line = fgets($this->stream);
            if ($line === false) {
                throw new RuntimeException('Соединение с IMAP-сервером прервано');
            }

            // Literal block: read exactly the announced number of bytes.
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $size = (int) $m[1];
                $data = '';
                while (strlen($data) < $size) {
                    $chunk = fread($this->stream, $size - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $data .= $chunk;
                }
                $this->literals[] = $data;
                $lines[] = rtrim($line, "\r\n");
                continue;
            }

            $lines[] = rtrim($line, "\r\n");

            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . preg_quote($tag, '/') . ' OK/i', $line)) {
                    throw new RuntimeException('IMAP: ' . trim($line));
                }
                break;
            }
        }

        return $lines;
    }

    private function readLine(): ?string
    {
        if ($this->stream === null) {
            return null;
        }
        $line = fgets($this->stream);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    private function quote(string $value): string
    {
        return '"' . addcslashes($value, "\\\"") . '"';
    }
}
