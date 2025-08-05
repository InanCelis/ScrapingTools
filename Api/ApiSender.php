<?php
// File: ApiSender.php

class ApiSender {
    private string $apiUrl;
    private string $token;
    private int $maxRetries;
    private int $timeout;
    private int $connectTimeout;
    private bool $debug;

    public function __construct(bool $debug = false) {
        $this->apiUrl = 'https://internationalpropertyalerts.com/wp-json/houzez/v1/properties';
        $this->token = 'eyJpYXQiOjE3NTQyNzY4NzUsImV4cCI6MTc1NDM2MzI3NX0=';
        $this->maxRetries = 5;            // Increased to 5 retry attempts
        $this->timeout = 120;             // 2 minute timeout for complete operation
        $this->connectTimeout = 30;       // 30 second connection timeout
        $this->debug = $debug;
    }

    public function sendProperty(array $propertyData): array {
        $postData = [
            'properties' => [$propertyData]
        ];

        $attempt = 0;
        $lastError = null;
        $lastResponse = null;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            $this->log("Attempt $attempt of {$this->maxRetries}");

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($postData),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Expect:' // Fixes 100-continue server issues
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FAILONERROR => false, // We'll handle errors manually
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TCP_KEEPALIVE => true,
                CURLOPT_TCP_KEEPIDLE => 120,
                CURLOPT_TCP_KEEPINTVL => 60
            ]);

            $startTime = microtime(true);
            $response = curl_exec($ch);
            $duration = round(microtime(true) - $startTime, 2);
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $lastResponse = $response;
            curl_close($ch);

            if ($error) {
                $lastError = "CURL Error: $error";
                $this->log("⚠️ Request failed: $lastError (Duration: {$duration}s)");
            } elseif ($httpCode >= 200 && $httpCode < 300) {
                $decodedResponse = json_decode($response, true) ?? $response;
                $this->log("✅ Success (HTTP $httpCode) in {$duration}s");
                return [
                    'success' => true,
                    'response' => $decodedResponse,
                    'attempts' => $attempt,
                    'duration' => $duration
                ];
            } else {
                $lastError = "HTTP $httpCode";
                $this->log("⚠️ Server responded with HTTP $httpCode (Duration: {$duration}s)");
                if ($this->debug && $response) {
                    $this->log("Response: " . substr($response, 0, 1000));
                }
            }

            if ($attempt < $this->maxRetries) {
                $sleepTime = min(10, pow(2, $attempt)); // Cap at 10 seconds max
                $this->log("⏳ Retrying in $sleepTime seconds...");
                sleep($sleepTime);
            }
        }

        $this->log("❌ All attempts failed. Last error: $lastError");
        return [
            'success' => false,
            'error' => $lastError,
            'attempts' => $attempt,
            'last_response' => $lastResponse,
            'http_code' => $httpCode ?? null
        ];
    }

    private function log(string $message): void {
        echo "[" . date('Y-m-d H:i:s') . "] $message\n";
        // Consider adding file logging here for production
    }
}