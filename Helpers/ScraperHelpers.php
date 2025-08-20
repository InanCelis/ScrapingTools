<?php
require_once __DIR__ . '/../simple_html_dom.php';

class ScraperHelpers {
    private string $apiKey = '04c7be9907947f8bdc0867d28854748b';

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

    private function locationDetails($result) {
        return [
            'location' => (string)$result['latitude'] . ', '.(string)$result['longitude'],
            'latitude' => (string)$result['latitude'],
            'longitude' => (string)$result['longitude'],
            'formatted_address' => $result['label'],
            'address' => !empty($result['locality']) 
            ? (string)$result['locality'] . ', ' . (string)$result['country']
            : (string)$result['region'] . ', ' . (string)$result['country'],
            'city' => (string)$result['locality'],
            'state' => (string)$result['region'],
            'country' => (string)$result['country'],
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
}