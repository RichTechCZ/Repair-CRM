<?php

class MyInvoiceApiException extends Exception {
    private int $statusCode;
    private ?array $responseBody;

    public function __construct(string $message, int $statusCode = 0, ?array $responseBody = null) {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getResponseBody(): ?array {
        return $this->responseBody;
    }
}

class MyInvoiceApiClient {
    private string $baseUrl;
    private string $token;
    private ?int $supplierId;

    public function __construct(?string $baseUrl = null, ?string $token = null, ?int $supplierId = null) {
        $configuredBaseUrl = $baseUrl ?: (getenv('MYINVOICE_API_BASE_URL') ?: get_setting('myinvoice_api_base_url', 'http://fakturace.43.157.31.121.sslip.io'));
        $this->baseUrl = rtrim($configuredBaseUrl, '/');
        $this->token = $token ?: (getenv('MYINVOICE_API_TOKEN') ?: '');
        $supplier = $supplierId ?? (getenv('MYINVOICE_SUPPLIER_ID') ?: null);
        $this->supplierId = ($supplier !== null && $supplier !== '') ? (int)$supplier : null;
    }

    public function isConfigured(): bool {
        return $this->token !== '' && $this->baseUrl !== '';
    }

    public function apiMe(): array {
        return $this->request('GET', '/api/v1/auth/api-me');
    }

    public function getCountries(): array {
        $data = $this->request('GET', '/api/v1/codebooks/countries');
        return $data['countries'] ?? $data;
    }

    public function getVatRates(): array {
        $data = $this->request('GET', '/api/v1/codebooks/vat-rates');
        return $data['vat_rates'] ?? $data['rates'] ?? $data;
    }

    public function findClients(string $query): array {
        $data = $this->request('GET', '/api/v1/clients?q=' . rawurlencode($query) . '&per_page=20');
        return $data['clients'] ?? [];
    }

    public function getClient(int $id): array {
        return $this->request('GET', '/api/v1/clients/' . $id);
    }

    public function createClient(array $payload): array {
        return $this->request('POST', '/api/v1/clients', $payload);
    }

    public function createInvoice(array $payload): array {
        return $this->request('POST', '/api/v1/invoices', $payload);
    }

    public function issueInvoice(int $id): array {
        return $this->request('POST', '/api/v1/invoices/' . $id . '/issue');
    }

    private function request(string $method, string $path, ?array $payload = null): array {
        if (!$this->isConfigured()) {
            throw new MyInvoiceApiException('MyInvoice API token is not configured.');
        }

        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        if ($this->supplierId) {
            $headers[] = 'X-Supplier-Id: ' . $this->supplierId;
        }

        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                throw new MyInvoiceApiException('Failed to encode MyInvoice API payload.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        if (function_exists('curl_init')) {
            return $this->requestWithCurl($method, $url, $headers, $body);
        }

        return $this->requestWithStreams($method, $url, $headers, $body);
    }

    private function requestWithCurl(string $method, string $url, array $headers, ?string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new MyInvoiceApiException('MyInvoice API transport error: ' . $error);
        }

        return $this->decodeResponse($raw, $status);
    }

    private function requestWithStreams(string $method, string $url, array $headers, ?string $body): array {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }

        if ($raw === false) {
            throw new MyInvoiceApiException('MyInvoice API transport error.');
        }

        return $this->decodeResponse($raw, $status);
    }

    private function decodeResponse(string $raw, int $status): array {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['raw' => $raw];
        }

        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message'] ?? $data['message'] ?? ('MyInvoice API HTTP ' . $status);
            throw new MyInvoiceApiException($message, $status, $data);
        }

        return $data;
    }
}
