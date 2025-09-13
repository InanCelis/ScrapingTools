<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';


class ScraperHelpers {
    private string $apiKey = '04c7be9907947f8bdc0867d28854748b';
    private ApiSender $apiSender;

    public function __construct() {
        // Initialize the ApiSender with your actual API URL and token
        $this->apiSender = new ApiSender(true);
        
    }

    public function getHtml(string $url): ?simple_html_dom {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 15,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) {
            echo "Failded HTML";
            return null;
        } 

        $dom = str_get_html($html);
        if ($dom) {
            return $dom;
        } else {
            echo "Failed DOM";
            return null;
        }
        
    }

    // PositionStack geocoding with your API key
    public function getCoordinatesFromQuery($query) {
        
        $encodedQuery = urlencode(trim($query));
        echo $encodedQuery."\n";
        $url = "http://api.positionstack.com/v1/forward?access_key={$this->apiKey}&query={$encodedQuery}&limit=1";
        
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'PropertyScraper/1.0'
                ]
            ]);
            
            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                error_log("PositionStack API request failed for query: {$query}");
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (!empty($data['data'][0])) {
                $result = $data['data'][0];
                return $this->locationDetails($result);
            } else {
                error_log("No results from PositionStack for query: {$query}");
            }
            
        } catch (Exception $e) {
            error_log("PositionStack API error: " . $e->getMessage());
        }
        
        return null;
    }



    // PositionStack geocoding with your API key
    public function getLocationDataByCoords($lat, $lang) {
        $url = "https://api.positionstack.com/v1/reverse?access_key={$this->apiKey}&query={$lat},{$lang}&limit=1";
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'PropertyScraper/1.0'
                ]
            ]);
            
            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                error_log("PositionStack API request failed for query: {$query}");
                return null;
            }
            
            $data = json_decode($response, true);
            
            if (!empty($data['data'][0])) {
                $result = $data['data'][0];
                return $this->locationDetails($result);
            } else {
                error_log("No results from PositionStack for query: {$query}");
            }
            
        } catch (Exception $e) {
            error_log("PositionStack API error: " . $e->getMessage());
        }
        
        return null;
    }

    // private function locationDetails($result) {
    //     return [
    //         'location' => (string)$result['latitude'] . ', '.(string)$result['longitude'],
    //         'latitude' => (string)$result['latitude'],
    //         'longitude' => (string)$result['longitude'],
    //         'formatted_address' => $result['label'],
    //         'address' => (!empty($result['locality']) || $result['locality'] == null) 
    //         ? (!empty($result['locality']) || $result['locality'] != null ) ? (string)$result['locality'] : (string)$result['county']  . ', ' . (string)$result['country']
    //         : (string)$result['region'] . ', ' . (string)$result['country'],
    //         'city' => (!empty($result['locality']) || $result['locality'] != null ) ? (string)$result['locality'] : (string)$result['county'],
    //         'state' => (string)$result['region'],
    //         'country' => (string)$result['country'],
    //         'postal_code' => (string)$result['postal_code'],
    //     ];
    // }


    private function locationDetails($result) {
        // Get individual components
        $city = !empty($result['locality']) ? (string)$result['locality'] : (string)$result['county'];
        $state = (string)$result['region'];
        $country = (string)$result['country'];
        
        // Build address array with non-empty components
        $addressParts = [];
        
        if (!empty($city)) {
            $addressParts[] = $city;
        }
        
        if (!empty($state)) {
            $addressParts[] = $state;
        }
        
        if (!empty($country)) {
            $addressParts[] = $country;
        }
        
        // Join with commas
        $address = implode(', ', $addressParts);
        
        return [
            'location' => (string)$result['latitude'] . ', ' . (string)$result['longitude'],
            'latitude' => (string)$result['latitude'],
            'longitude' => (string)$result['longitude'],
            'formatted_address' => (string)$result['label'],
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'postal_code' => (string)$result['postal_code'],
        ];
    }
    public function translateHtmlPreservingTags(string $html): string {
        $html = "<div>$html</div>";
        $translated = preg_replace_callback('/>([^<>]+)</', function ($matches) {
            $text = trim($matches[1]);
            if ($text === '') return '><';
            $translatedText = $text;
            return ">$translatedText<";
        }, $html);

        return preg_replace('/^<div>|<\/div>$/', '', $translated);
    }


    public function getHtmlWithJS(string $url): ?simple_html_dom {
        $tempFile = tempnam(sys_get_temp_dir(), 'scraped_html_');
        
        // Updated path to your puppeteer script in Helpers/js folder
        $puppeteerScript = __DIR__ . '/js/puppeteer-scraper.js';
        
        // Check if the puppeteer script exists
        if (!file_exists($puppeteerScript)) {
            echo "❌ Puppeteer script not found at: $puppeteerScript\n";
            echo "💡 Make sure you've created the file and installed puppeteer in Helpers/js/\n";
            return null;
        }
        
        // Check if node is available
        exec('node --version 2>&1', $nodeCheck, $nodeReturnCode);
        if ($nodeReturnCode !== 0) {
            echo "❌ Node.js not found. Please install Node.js first.\n";
            return null;
        }
        
        $helperJsDir = dirname($puppeteerScript);
        
        // Execute Puppeteer script with proper escaping
        if (PHP_OS_FAMILY === 'Windows') {
            $command = sprintf(
                'cd /d "%s" && node "%s" "%s" "%s" 2>&1',
                $helperJsDir,
                basename($puppeteerScript),
                $url,
                $tempFile
            );
        } else {
            $command = sprintf(
                'cd %s && node %s %s %s 2>&1',
                escapeshellarg($helperJsDir),
                escapeshellarg(basename($puppeteerScript)),
                escapeshellarg($url),
                escapeshellarg($tempFile)
            );
        }
        
        echo "🚀 Executing: $command\n";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            echo "❌ Puppeteer failed with return code $returnCode\n";
            echo "❌ Output: " . implode("\n", $output) . "\n";
            
            // Try to use fallback cURL method
            echo "🔄 Trying fallback cURL method...\n";
            return $this->getHtml($url);
        }
        
        if (!file_exists($tempFile)) {
            echo "❌ HTML file not created at: $tempFile\n";
            echo "🔄 Trying fallback cURL method...\n";
            return $this->getHtml($url);
        }
        
        $html = file_get_contents($tempFile);
        unlink($tempFile); // Clean up temp file
        
        if (!$html) {
            echo "❌ Failed to read HTML content\n";
            echo "🔄 Trying fallback cURL method...\n";
            return $this->getHtml($url);
        }
        
        echo "✅ Successfully got HTML content (" . strlen($html) . " characters)\n";
        
        // Use DOMDocument instead of simple_html_dom for large HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        // Convert DOMDocument to simple_html_dom compatible format
        $cleanHtml = $dom->saveHTML();
        
        // Now try with simple_html_dom
        $simpleDom = str_get_html($cleanHtml);
        
        if (!$simpleDom) {
            echo "❌ Still failed to parse with simple_html_dom, trying alternative approach\n";
            
            // Save the HTML to a temporary file and load it
            $tempHtmlFile = tempnam(sys_get_temp_dir(), 'html_');
            file_put_contents($tempHtmlFile, $cleanHtml);
            $simpleDom = file_get_html($tempHtmlFile);
            unlink($tempHtmlFile);
            
            if (!$simpleDom) {
                echo "❌ All parsing methods failed\n";
                return null;
            }
        }
        
        echo "✅ Successfully parsed HTML\n";
        return $simpleDom;
    }


    private static $usedIds = [];
    public static function generateReferenceId() {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $maxAttempts = 100; // Prevent infinite loops
        
        do {
            $referenceId = '';
            for ($i = 0; $i < 6; $i++) {
                $referenceId .= $characters[random_int(0, strlen($characters) - 1)];
            }
            $maxAttempts--;
        } while (in_array($referenceId, self::$usedIds) && $maxAttempts > 0);
        
        if ($maxAttempts <= 0) {
            throw new Exception("Unable to generate unique reference ID");
        }
        
        self::$usedIds[] = $referenceId;
        return $referenceId;
    }
    
    public static function reset() {
        self::$usedIds = [];
    }


    public function updatePostToDraft(string $url): void {
    
        // Make HTTP request to get listing_id from URL
        $apiUrl = "https://internationalpropertyalerts.com/wp-json/houzez/v1/listing-by-url?url=" . urlencode($url);
        
        echo "Fetching listing ID for URL: $url\n";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            echo "❌ CURL Error: $error\n";
            return;
        }

        if ($httpCode !== 200) {
            echo "❌ API Error: HTTP $httpCode\n";
            if ($httpCode === 404) {
                echo "   No property found with URL: $url\n";
            }
            return;
        }

        $data = json_decode($response, true);
        $listing_id = $data['listing_id'] ?? null;

        if (empty($listing_id)) {
            echo "❌ Error: No listing ID returned from API\n";
            echo "   URL: $url\n";
            return;
        }

        echo "✅ Found listing ID: $listing_id\n";
        echo "Updating property to draft status...\n";
        
        $result = $this->apiSender->updatePropertyToDraft($listing_id);
        
        if ($result['success']) {
            echo "✅ Success: Property successfully updated to draft status\n";
            echo "   Listing ID: $listing_id\n";
            echo "   URL: $url\n";
            
            if (isset($result['data']['property_id'])) {
                echo "   Property ID: " . $result['data']['property_id'] . "\n";
            }
            
            if (isset($result['data']['property_title'])) {
                echo "   Property Title: " . $result['data']['property_title'] . "\n";
            }
            
            if (isset($result['duration'])) {
                echo "   Duration: " . $result['duration'] . "s\n";
            }
        } else {
            echo "❌ Error: " . ($result['error'] ?? 'Unknown error occurred') . "\n";
            echo "   Listing ID: $listing_id\n";
            echo "   URL: $url\n";
            
            if (isset($result['http_code'])) {
                echo "   HTTP Code: " . $result['http_code'] . "\n";
            }
        }
        
        echo "\n"; // Add spacing between operations
    }
}