<?php

namespace App\Services\Mail;

/**
 * Small, dependency-free MIME parser.
 *
 * It is intentionally tolerant: it decodes RFC 2047 encoded headers, walks
 * multipart bodies recursively and extracts the plain-text / HTML parts plus
 * any attachments. It is not a full RFC 5322 implementation but covers the
 * messages produced by mainstream mail clients and servers.
 */
class MimeParser
{
    /**
     * @return array{
     *     headers: array<string, string>,
     *     from: array{name: ?string, email: ?string}|null,
     *     to: array<int, array{name: ?string, email: ?string}>,
     *     cc: array<int, array{name: ?string, email: ?string}>,
     *     subject: ?string,
     *     message_id: ?string,
     *     in_reply_to: ?string,
     *     date: ?string,
     *     text: ?string,
     *     html: ?string,
     *     attachments: array<int, array{filename: string, mime: ?string, content: string, size: int}>
     * }
     */
    public function parse(string $raw): array
    {
        [$headerBlock, $body] = $this->splitHeaderBody($raw);
        $headers = $this->parseHeaders($headerBlock);

        $result = [
            'headers' => $headers,
            'from' => $this->firstAddress($headers['from'] ?? null),
            'to' => $this->parseAddressList($headers['to'] ?? null),
            'cc' => $this->parseAddressList($headers['cc'] ?? null),
            'subject' => isset($headers['subject']) ? $this->decodeHeader($headers['subject']) : null,
            'message_id' => $this->cleanId($headers['message-id'] ?? null),
            'in_reply_to' => $this->cleanId($headers['in-reply-to'] ?? null),
            'date' => $headers['date'] ?? null,
            'text' => null,
            'html' => null,
            'attachments' => [],
        ];

        $this->walk($headers, $body, $result);

        if ($result['text'] !== null) {
            $result['text'] = trim($result['text']);
        }
        if ($result['html'] !== null) {
            $result['html'] = trim($result['html']);
        }

        return $result;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $result
     */
    private function walk(array $headers, string $body, array &$result): void
    {
        $contentType = $headers['content-type'] ?? 'text/plain';
        [$mime, $params] = $this->parseContentType($contentType);
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));
        $disposition = strtolower($this->paramValue($headers['content-disposition'] ?? '', 'disposition', ''));

        if (str_starts_with($mime, 'multipart/')) {
            $boundary = $this->paramValue($contentType, 'boundary', null);
            if ($boundary === null || $boundary === '') {
                return;
            }
            foreach ($this->splitParts($body, $boundary) as $part) {
                [$partHeaderBlock, $partBody] = $this->splitHeaderBody($part);
                $partHeaders = $this->parseHeaders($partHeaderBlock);
                $this->walk($partHeaders, $partBody, $result);
            }

            return;
        }

        $decoded = $this->decodeBody($body, $encoding);
        [, $params] = $this->parseContentType($contentType);
        $charset = $params['charset'] ?? null;
        $decoded = $this->toUtf8($decoded, $charset);

        $isAttachment = $disposition === 'attachment'
            || ($this->paramValue($headers['content-disposition'] ?? '', 'filename', null) !== null)
            || ($this->paramValue($contentType, 'name', null) !== null);

        if ($mime === 'text/plain' && !$isAttachment && $result['text'] === null) {
            $result['text'] = $decoded;
        } elseif ($mime === 'text/html' && !$isAttachment && $result['html'] === null) {
            $result['html'] = $decoded;
        } elseif ($isAttachment || !str_starts_with($mime, 'text/')) {
            $filename = $this->paramValue($headers['content-disposition'] ?? '', 'filename', null)
                ?? $this->paramValue($contentType, 'name', null)
                ?? 'attachment';
            $filename = $this->decodeHeader($filename);
            if ($filename !== '' && $mime !== 'application/octet-stream' || $isAttachment) {
                $result['attachments'][] = [
                    'filename' => $filename,
                    'mime' => $mime,
                    'content' => $decoded,
                    'size' => strlen($decoded),
                ];
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitHeaderBody(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $pos = strpos($raw, "\n\n");
        if ($pos === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $block): array
    {
        $headers = [];
        $lines = explode("\n", $block);
        $current = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current] .= ' ' . trim($line);

                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $headers[$name] = trim(substr($line, $colon + 1));
            $current = $name;
        }

        return $headers;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseContentType(string $value): array
    {
        $parts = explode(';', $value);
        $mime = strtolower(trim($parts[0] ?? 'text/plain'));
        $params = [];

        for ($i = 1; $i < count($parts); $i++) {
            $kv = explode('=', $parts[$i], 2);
            if (count($kv) === 2) {
                $params[strtolower(trim($kv[0]))] = trim(trim($kv[1]), '"');
            }
        }

        return [$mime !== '' ? $mime : 'text/plain', $params];
    }

    private function paramValue(string $header, string $name, ?string $default): ?string
    {
        if ($header === '') {
            return $default;
        }
        [, $params] = $this->parseContentType($header);

        return $params[$name] ?? $default;
    }

    /**
     * @return array<int, string>
     */
    private function splitParts(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        $segments = explode($delimiter, $body);
        $parts = [];

        foreach ($segments as $segment) {
            $trimmed = ltrim($segment, "\n");
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $parts[] = rtrim($segment, "\n");
        }

        return $parts;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function toUtf8(string $value, ?string $charset): string
    {
        if ($charset === null || $charset === '' || strtolower($charset) === 'utf-8') {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', $charset);

        return $converted === false ? $value : $converted;
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    private function cleanId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value, " \t<>");

        return $value === '' ? null : $value;
    }

    /**
     * @return array{name: ?string, email: ?string}|null
     */
    private function firstAddress(?string $value): ?array
    {
        $list = $this->parseAddressList($value);

        return $list[0] ?? null;
    }

    /**
     * @return array<int, array{name: ?string, email: ?string}>
     */
    private function parseAddressList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $result = [];
        foreach ($this->splitAddresses($value) as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            $result[] = $this->parseSingleAddress($address);
        }

        return $result;
    }

    /**
     * Split on commas that are not inside quotes or angle brackets.
     *
     * @return array<int, string>
     */
    private function splitAddresses(string $value): array
    {
        $parts = [];
        $buffer = '';
        $inQuotes = false;
        $inAngle = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($char === '<' && !$inQuotes) {
                $inAngle = true;
            } elseif ($char === '>' && !$inQuotes) {
                $inAngle = false;
            } elseif ($char === ',' && !$inQuotes && !$inAngle) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        $parts[] = $buffer;

        return $parts;
    }

    /**
     * @return array{name: ?string, email: ?string}
     */
    private function parseSingleAddress(string $address): array
    {
        if (preg_match('/^(.*)<([^>]+)>$/', $address, $m)) {
            $name = trim(trim($m[1]), '"');
            $name = $this->decodeHeader($name);

            return [
                'name' => $name !== '' ? $name : null,
                'email' => trim($m[2]),
            ];
        }

        return ['name' => null, 'email' => trim($address, " \t<>")];
    }
}
