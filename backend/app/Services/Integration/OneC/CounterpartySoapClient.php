<?php

namespace App\Services\Integration\OneC;

use DOMDocument;
use DOMXPath;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SOAP client for the 1С «CounterpartiesExchange» web service.
 *
 * Operation: GetCounterparties(INN, DescriptionPart, Offset, Portion) -> xs:string
 * where the string is a JSON document nested inside the SOAP `return` element
 * (XML-escaped). See docs/1c.md.
 */
class CounterpartySoapClient
{
    public function configured(): bool
    {
        return CounterpartyConfig::fromSettings()->configured();
    }

    /**
     * Fetch a single page of counterparties.
     *
     * @return array{Total:int,Offset:int,Portion:int,Counterparties:array<int,array<string,mixed>>}|null
     */
    public function getCounterparties(string $inn = '', string $descriptionPart = '', int $offset = 0, int $portion = 100): ?array
    {
        $config = CounterpartyConfig::fromSettings();

        if (!$config->configured()) {
            return null;
        }

        $portion = $this->normalizePortion($portion);
        $offset = max(0, $offset);

        try {
            $response = $this->send($config, $inn, $descriptionPart, $offset, $portion);
        } catch (Throwable $e) {
            Log::warning('1C counterparties SOAP request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            // 401 = wrong credentials; SOAP Fault usually comes with 500.
            Log::warning('1C counterparties SOAP error', ['status' => $response->status()]);

            return null;
        }

        return $this->parse((string) $response->body());
    }

    /**
     * One-page search by INN and/or name substring.
     *
     * @return array{Total:int,Offset:int,Portion:int,Counterparties:array<int,array<string,mixed>>}|null
     */
    public function search(?string $inn, ?string $descriptionPart, int $portion = 50): ?array
    {
        return $this->getCounterparties(
            (string) ($inn ?? ''),
            (string) ($descriptionPart ?? ''),
            0,
            $portion,
        );
    }

    /**
     * Iterate every page. Yields normalised page payloads.
     *
     * @return Generator<int, array{Total:int,Offset:int,Portion:int,Counterparties:array<int,array<string,mixed>>}>
     */
    public function fetchAll(?int $portion = null, ?int $maxPages = null): Generator
    {
        $config = CounterpartyConfig::fromSettings();
        $portion = $this->normalizePortion($portion ?? $config->portion());
        $maxPages = $maxPages ?? $config->maxPages();
        $offset = 0;
        $pages = 0;

        while ($pages < max(1, $maxPages)) {
            $page = $this->getCounterparties('', '', $offset, $portion);

            if ($page === null) {
                throw new OneCSoapException('1C counterparties request failed');
            }

            yield $page;
            $pages++;

            $total = $page['Total'];
            // 1С may clamp the portion; advance by what it actually returned.
            $returned = $page['Portion'] > 0 ? $page['Portion'] : $portion;
            $count = count($page['Counterparties']);

            if ($count === 0 || $total <= $offset + $returned) {
                return;
            }

            $offset += $returned;

            if ($offset >= $total) {
                return;
            }
        }

        Log::warning('1C counterparties: page cap reached', ['max_pages' => $maxPages]);
    }

    private function send(CounterpartyConfig $config, string $inn, string $descriptionPart, int $offset, int $portion): Response
    {
        $namespace = $config->namespace();

        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:c="' . $namespace . '">'
            . '<soapenv:Body><c:GetCounterparties>'
            . '<c:INN>' . $this->escape($inn) . '</c:INN>'
            . '<c:DescriptionPart>' . $this->escape($descriptionPart) . '</c:DescriptionPart>'
            . '<c:Offset>' . $offset . '</c:Offset>'
            . '<c:Portion>' . $portion . '</c:Portion>'
            . '</c:GetCounterparties></soapenv:Body></soapenv:Envelope>';

        return Http::timeout($config->timeout())
            ->withOptions(['verify' => $config->verify()])
            ->withBasicAuth($config->login(), $config->password())
            ->withHeaders([
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => $namespace . '#CounterpartiesExchange:GetCounterparties',
            ])
            ->withBody($xml, 'text/xml; charset=utf-8')
            ->post((string) $config->url());
    }

    /**
     * @return array{Total:int,Offset:int,Portion:int,Counterparties:array<int,array<string,mixed>>}|null
     */
    private function parse(string $body): ?array
    {
        if (trim($body) === '') {
            Log::warning('1C counterparties: empty SOAP response');

            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();

        if (!$loaded) {
            Log::warning('1C counterparties: malformed SOAP XML');

            return null;
        }

        $xpath = new DOMXPath($dom);

        $fault = $xpath->query('//*[local-name()="Fault"]');
        if ($fault !== false && $fault->length > 0) {
            Log::warning('1C counterparties SOAP fault', ['fault' => (string) $fault->item(0)?->textContent]);

            return null;
        }

        $nodes = $xpath->query('//*[local-name()="GetCounterpartiesResponse"]/*[local-name()="return"]');
        $raw = ($nodes !== false && $nodes->length > 0) ? (string) $nodes->item(0)?->textContent : '';

        if (trim($raw) === '') {
            Log::warning('1C counterparties: empty return element');

            return null;
        }

        $payload = json_decode($raw, true);

        // Some 1С builds double-escape the payload.
        if (!is_array($payload)) {
            $payload = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8'), true);
        }

        if (!is_array($payload)) {
            Log::warning('1C counterparties: return is not JSON');

            return null;
        }

        $rows = array_values(array_filter((array) ($payload['Counterparties'] ?? []), 'is_array'));

        return [
            'Total' => (int) ($payload['Total'] ?? 0),
            'Offset' => (int) ($payload['Offset'] ?? 0),
            'Portion' => (int) ($payload['Portion'] ?? 0),
            'Counterparties' => $rows,
        ];
    }

    private function normalizePortion(int $portion): int
    {
        if ($portion <= 0) {
            return 100;
        }

        return min($portion, 1000);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
