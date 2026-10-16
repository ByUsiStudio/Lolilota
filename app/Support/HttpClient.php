<?php

declare(strict_types=1);

namespace Lolilota\Support;

use Lolilota\Core\Config;
use Lolilota\Core\Security;

/**
 * HTTP 客户端
 *
 * 优先使用 cURL（若扩展存在），否则回落到 PHP 流封装器。
 * 注意：https 请求需要 openssl 扩展；未启用时会在错误里明确提示，
 * 而不是抛一个看不懂的 warning。
 */
final class HttpClient
{
    private int $timeout;
    private bool $verifyTls;
    private string $userAgent;

    /** @var list<string> */
    private array $requestHeaders = [];

    /** @var array<string, mixed> */
    private array $lastDebug = [];

    public function __construct(?int $timeout = null, ?bool $verifyTls = null)
    {
        $this->timeout = $timeout ?? (int) Config::get('cdifit.timeout', 15);
        $this->verifyTls = $verifyTls ?? (bool) Config::get('cdifit.verify_tls', true);
        $this->userAgent = 'Lolilota/1.1 (+https://github.com/byusistudio/lolilota)';
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->requestHeaders[] = $name . ': ' . $value;
        }
        return $clone;
    }

    /** @return array<string, mixed> */
    public function lastDebug(): array
    {
        return $this->lastDebug;
    }

    /**
     * 发送 JSON 请求
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>}
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{}';
        }

        return $this->request('POST', $url, $payload, array_merge([
            'Content-Type'   => 'application/json',
            'Content-Length' => (string) strlen($payload),
        ], $headers));
    }

    /**
     * 发送表单请求
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>}
     */
    public function postForm(string $url, array $body, array $headers = []): array
    {
        $payload = http_build_query($body, '', '&', PHP_QUERY_RFC3986);

        return $this->request('POST', $url, $payload, array_merge([
            'Content-Type'   => 'application/x-www-form-urlencoded',
            'Content-Length' => (string) strlen($payload),
        ], $headers));
    }

    /**
     * 通用请求
     *
     * @param array<string, string> $headers
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>}
     */
    public function request(string $method, string $url, string $payload = '', array $headers = []): array
    {
        $current = $this->requestHeaders;
        foreach ($headers as $name => $value) {
            $current[] = $name . ': ' . $value;
        }
        if ($current === []) {
            $current[] = 'User-Agent: ' . $this->userAgent;
        }

        $this->lastDebug = ['method' => $method, 'url' => $url, 'headers' => $current];

        if (function_exists('curl_init')) {
            return $this->viaCurl($method, $url, $payload, $current);
        }

        return $this->viaStream($method, $url, $payload, $current);
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>}
     */
    private function viaCurl(string $method, string $url, string $payload, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => $this->userAgent,
        ]);

        if ($method !== 'GET' && $payload !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false || $errno !== 0) {
            throw new HttpException('cdifit_unreachable', '无法连接认证服务：' . ($error ?: '未知网络错误'), 0);
        }

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $rawBody = substr((string) $response, $headerSize);

        return [
            'status'  => $status,
            'body'    => $this->decode($rawBody),
            'raw'     => $rawBody,
            'headers' => $this->parseHeaders($rawHeaders),
        ];
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:mixed, raw:string, headers:array<string,string>}
     */
    private function viaStream(string $method, string $url, string $payload, array $headers): array
    {
        if (str_starts_with($url, 'https://') && !extension_loaded('openssl')) {
            throw new HttpException(
                'php_openssl_missing',
                '当前 PHP 未启用 openssl 扩展，无法访问 https 认证服务。请在 php.ini 中开启 extension=openssl 后重启。',
                0
            );
        }

        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $payload,
                'timeout'         => $this->timeout,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'protocol_version'=> 1.1,
            ],
            'ssl' => [
                'verify_peer'      => $this->verifyTls,
                'verify_peer_name' => $this->verifyTls,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $error = error_get_last();
            throw new HttpException(
                'cdifit_unreachable',
                '无法连接认证服务：' . ($error['message'] ?? '网络不可达'),
                0
            );
        }

        $status = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $responseHeaders = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return [
            'status'  => $status,
            'body'    => $this->decode($raw),
            'raw'     => $raw,
            'headers' => $responseHeaders,
        ];
    }

    private function decode(string $raw): mixed
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 部分实现用 URL 编码返回
        if (str_contains($trimmed, '=') && !str_contains($trimmed, '{')) {
            $parsed = [];
            parse_str($trimmed, $parsed);
            if ($parsed !== []) {
                return $parsed;
            }
        }

        return $trimmed;
    }

    /** @return array<string, string> */
    private function parseHeaders(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n/', $raw) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $out[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        return $out;
    }

    /** 生成一个用于调试的签名（不泄露原文） */
    public function fingerprint(string $value): string
    {
        return substr(Security::sign($value), 0, 12);
    }
}
