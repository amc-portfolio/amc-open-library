<?php

declare(strict_types=1);

namespace OpenLibrary\Http;

use OpenLibrary\Exception\NetworkException;

/**
 * cURL-based transport. No external dependencies.
 */
final class CurlTransport implements TransportInterface
{
    public function __construct(
        private int $timeoutSeconds = 10,
        private int $connectTimeoutSeconds = 10,
    ) {
    }

    public function request(string $method, string $url, array $headers = []): Response
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new NetworkException('Failed to initialise cURL handle for ' . $url);
        }

        $rawHeaders = '';
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$rawHeaders): int {
                $rawHeaders .= $header;

                return strlen($header);
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $errno = (int) curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            throw new NetworkException("cURL error {$errno}: {$error} for {$url}", $errno);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, (string) $body, $this->parseHeaders($rawHeaders));
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue; // status line or blank
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
