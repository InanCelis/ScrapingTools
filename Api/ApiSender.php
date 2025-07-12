<?php
// File: ApiSender.php

class ApiSender {
    private string $apiUrl;
    private string $token;

    public function __construct(string $apiUrl, string $token) {
        $this->apiUrl = $apiUrl;
        $this->token = $token;
    }

    public function sendProperty(array $propertyData): array {
        $postData = [
            'properties' => [$propertyData]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            echo "⚠️ API Request Error: " . $error . "\n";
            return ['success' => false, 'error' => $error];
        }

        $decodedResponse = json_decode($response, true) ?? $response;

        if ($httpCode >= 200 && $httpCode < 300) {
            echo "✅ Successfully sent property to API\n";
            return ['success' => true, 'response' => $decodedResponse];
        } else {
            echo "⚠️ API Request Failed with status $httpCode. Response: " . print_r($decodedResponse, true) . "\n";
            return ['success' => false, 'http_code' => $httpCode, 'response' => $decodedResponse];
        }
    }
}