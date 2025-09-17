<?php
// File: ApiSender.php

class ApiSender {
    private string $apiUrl;
    private string $draftApiUrl;
    private string $token;
    private int $maxRetries;
    private int $timeout;
    private int $connectTimeout;
    private bool $debug;

    public function __construct(bool $debug = false) {
        $this->apiUrl = 'https://internationalpropertyalerts.com/wp-json/houzez/v1/properties';
        $this->linksApiUrl = 'https://internationalpropertyalerts.com/wp-json/houzez/v1/links-by-owner';
        $this->draftApiUrl = 'https://internationalpropertyalerts.com/wp-json/houzez/v1/properties';
        $this->token = 'eyJpYXQiOjE3NTgwMDY0NjAsImV4cCI6MTc1ODA5Mjg2MH0=';
        $this->maxRetries = 3;            // Increased to 5 retry attempts
        $this->timeout = 600;             // 2 minute timeout for complete operation
        $this->connectTimeout = 60;       // 30 second connection timeout
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


    public function getPropertyLinks(string $owner, ?int $start = null, ?int $end = null): array {
        try {
            $logMessage = "Fetching property links for owner: $owner";
            if ($start !== null && $end !== null) {
                $logMessage .= " (range: $start to $end)";
            }
            $this->log($logMessage);
            
            // Build URL with parameters
            $url = $this->linksApiUrl . '?owner=' . urlencode($owner);
            if ($start !== null) {
                $url .= '&start=' . $start;
            }
            if ($end !== null) {
                $url .= '&end=' . $end;
            }
            
            // Make API request to get property links (no token needed)
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);

            $startTime = microtime(true);
            $response = curl_exec($ch);
            $duration = round(microtime(true) - $startTime, 2);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $this->log("CURL Error: $error");
                return [
                    'success' => false,
                    'error' => "CURL Error: $error",
                    'links' => [],
                    'count' => 0
                ];
            }

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                
                if ($data && isset($data['links']) && is_array($data['links'])) {
                    $totalCount = $data['count'] ?? count($data['links']);
                    $returnedCount = count($data['links']);
                    
                    $logMessage = "Retrieved $returnedCount";
                    if ($start !== null && $end !== null) {
                        $logMessage .= " of " . ($data['pagination']['total_count'] ?? 'unknown') . " total";
                    }
                    $logMessage .= " property links in {$duration}s";
                    $this->log($logMessage);
                    
                    return [
                        'success' => true,
                        'links' => $data['links'],
                        'count' => $returnedCount,
                        'total_count' => $data['pagination']['total_count'] ?? $totalCount,
                        'pagination' => $data['pagination'] ?? null,
                        'start' => $start,
                        'end' => $end,
                        'duration' => $duration
                    ];
                } else {
                    $this->log("API response format unexpected");
                    if ($this->debug) {
                        $this->log("Response: " . substr($response, 0, 200));
                    }
                    return [
                        'success' => false,
                        'error' => 'Invalid API response format',
                        'links' => [],
                        'count' => 0,
                        'raw_response' => $response
                    ];
                }
            } else {
                $this->log("API request failed with HTTP code: $httpCode");
                if ($this->debug) {
                    $this->log("Response: " . substr($response, 0, 200));
                }
                return [
                    'success' => false,
                    'error' => "HTTP $httpCode",
                    'links' => [],
                    'count' => 0,
                    'http_code' => $httpCode,
                    'raw_response' => $response
                ];
            }

        } catch (Exception $e) {
            $this->log("Exception while fetching links from API: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'links' => [],
                'count' => 0
            ];
        }
    }

     /**
     * Update a single property to draft status by listing ID
     * @param string $listingId The listing ID of the property to update
     * @return array Array containing success status and response data
     */
    public function updatePropertyToDraft(string $listingId): array {
        try {
            $this->log("Updating property to draft status. Listing ID: $listingId");
            
            $url = $this->draftApiUrl . '/' . urlencode($listingId) . '/draft';
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FAILONERROR => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3
            ]);

            $startTime = microtime(true);
            $response = curl_exec($ch);
            $duration = round(microtime(true) - $startTime, 2);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $this->log("CURL Error: $error");
                return [
                    'success' => false,
                    'error' => "CURL Error: $error",
                    'listing_id' => $listingId
                ];
            }

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                
                if ($data && isset($data['success']) && $data['success']) {
                    $this->log("Successfully updated property to draft in {$duration}s");
                    return [
                        'success' => true,
                        'data' => $data,
                        'listing_id' => $listingId,
                        'duration' => $duration
                    ];
                } else {
                    $this->log("API returned success=false or unexpected format");
                    return [
                        'success' => false,
                        'error' => $data['message'] ?? 'Unknown API error',
                        'listing_id' => $listingId,
                        'raw_response' => $response
                    ];
                }
            } elseif ($httpCode === 404) {
                $this->log("Property not found (HTTP 404)");
                return [
                    'success' => false,
                    'error' => 'Property not found',
                    'listing_id' => $listingId,
                    'http_code' => $httpCode
                ];
            } else {
                $this->log("API request failed with HTTP code: $httpCode");
                if ($this->debug) {
                    $this->log("Response: " . substr($response, 0, 500));
                }
                return [
                    'success' => false,
                    'error' => "HTTP $httpCode",
                    'listing_id' => $listingId,
                    'http_code' => $httpCode,
                    'raw_response' => $response
                ];
            }

        } catch (Exception $e) {
            $this->log("Exception while updating property to draft: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'listing_id' => $listingId
            ];
        }
    }


    private function log(string $message): void {
        echo "[" . date('Y-m-d H:i:s') . "] $message\n";
        // Consider adding file logging here for production
    }
}