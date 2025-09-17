<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class MarbellaRealtyGroup {
    private string $baseUrl = "https://marbellarealtygroup.com";
    private array $propertyLinks = [];
    private array $scrapedData = [];
    private ApiSender $apiSender;
    private int $successCreated;
    private int $successUpdated;
    private ScraperHelpers $helpers;

    public function __construct() {
        // Initialize the ApiSender with your actual API URL and token
        $this->apiSender = new ApiSender(true);
        $this->helpers = new ScraperHelpers();
        $this->successCreated = 0;
        $this->successUpdated = 0;
    }

    public function run(int $pageCount = 1, int $limit = 0): void {
        $folder = __DIR__ . '/../ScrapeFile/MarbellaRealtyGroup';
        $outputFile = $folder . '/RestoredVersion2.json';
        // $htmlTest =  $folder . '/Test.html';
        // Create the folder if it doesn't exist
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Start a fresh JSON array
        file_put_contents($outputFile, "[");

        $propertyCounter = 0;
        for ($page = 1; $page <= $pageCount; $page++) {
            // $url = $this->baseUrl . "/property-for-sale/?il_page={$page}&listing_type=resale&type%5B0%5D=1&type%5B1%5D=7&type%5B2%5D=2&type%5B3%5D=8&type%5B4%5D=3&type%5B5%5D=5&type%5B6%5D=6&type%5B7%5D=4&bedrooms_min&bathrooms_min&list_price_min=50000&list_price_max=150000&ref_no&order=list_price_asc";
            $url = $this->baseUrl . "/property-for-sale/?il_page={$page}&listing_type=resale&type%5B%5D=1&type%5B%5D=7&type%5B%5D=2&type%5B%5D=8&type%5B%5D=3&type%5B%5D=5&type%5B%5D=6&type%5B%5D=4&bedrooms_min=&bathrooms_min=&list_price_min=50000&list_price_max=10000000&ref_no=&order=list_price_desc";
            
            echo "📄 Fetching page $page: $url\n";

            // Use the getHtml method instead of file_get_html
            $html = $this->getHtml($url);
            if (!$html) {
                echo "⚠️ Failed to load page $page. Skipping...\n";
                continue;
            }
            $this->extractPropertyLinks($html);
            
            // Add delay between page requests to avoid rate limiting
            sleep(rand(3, 7)); // Random delay between 3-7 seconds
        }

        // Deduplicate array of arrays
        $this->propertyLinks = array_map("unserialize", array_unique(array_map("serialize", $this->propertyLinks)));
        if ($limit > 0) {
            $this->propertyLinks = array_slice($this->propertyLinks, 0, $limit);
        }
        
        $countLinks = 1;
        // Get total count of property links
        $totalLinks = count($this->propertyLinks);
        echo "📊 Total properties to scrape: {$totalLinks}\n\n";
        foreach ($this->propertyLinks as $url) {
            echo "URL ".$countLinks++." 🔍 Scraping: $url\n";
            
            // Use getHtml method here too
            $propertyHtml = $this->getHtml($url);
            if ($propertyHtml) {
                $this->scrapedData = []; // Clear for fresh 
                // file_put_contents($htmlTest, $propertyHtml);
                // return;

                $this->scrapePropertyDetails($propertyHtml, $url);

                if (!empty($this->scrapedData[0])) {
                    $jsonEntry = json_encode($this->scrapedData[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    file_put_contents($outputFile, ($propertyCounter > 0 ? "," : "") . "\n" . $jsonEntry, FILE_APPEND);
                    $propertyCounter++;

                    // Send the property data via the ApiSender
                    $result = $this->apiSender->sendProperty($this->scrapedData[0]);
                    if ($result['success']) {
                        echo "✅ Success after {$result['attempts']} attempt(s)\n";
                        if (count($result['response']['updated_properties']) > 0) {
                            echo "✅ Updated # " . $this->successUpdated++ . "\n";
                        } else {
                            echo "✅ Created # " . $this->successCreated++ . "\n";
                        }
                    } else {
                        echo "❌ Failed after {$result['attempts']} attempts. Last error: {$result['error']}\n";
                        if ($result['http_code']) {
                            echo "⚠️ HTTP Status: {$result['http_code']}\n";
                        }
                    }
                    sleep(1);
                }
            }
            
            // Add longer delay between property requests to avoid rate limiting
            sleep(rand(2, 5)); // Random delay between 2-5 seconds
        }

        // Close the JSON array
        file_put_contents($outputFile, "\n]", FILE_APPEND);

        echo "✅ Scraping completed. Output saved to {$outputFile}\n";
        echo "✅ Properties Created: {$this->successCreated}\n";
        echo "✅ Properties Updated: {$this->successUpdated}\n";
    }

    private function getHtml(string $url): ?simple_html_dom {
        // Add random delay before each request to avoid rate limiting
        $delay = rand(1, 3);
        echo "⏳ Waiting {$delay} seconds before request...\n";
        sleep($delay);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60, // Increased timeout for slower loading
            CURLOPT_CONNECTTIMEOUT => 30, // Increased connection timeout
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            // Enhanced headers to look more like a real browser
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Cache-Control: max-age=0',
                'Referer: https://marbellarealtygroup.com/', // Add referer
            ],
            // Handle gzip and deflate encoding specifically
            CURLOPT_ENCODING => 'gzip, deflate',
            // Add cookie handling for session persistence
            CURLOPT_COOKIEJAR => sys_get_temp_dir() . '/scraper_cookies.txt',
            CURLOPT_COOKIEFILE => sys_get_temp_dir() . '/scraper_cookies.txt',
        ]);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            echo "❌ cURL Error: $error\n";
            return null;
        }
        
        if ($httpCode === 429) {
            echo "❌ Rate limited (429). Waiting 30 seconds before retrying...\n";
            sleep(30);
            return $this->getHtml($url); // Retry the request
        }
        
        if ($httpCode !== 200) {
            echo "❌ HTTP Error: $httpCode\n";
            return null;
        }
        
        // Additional wait to ensure dynamic content loads
        echo "✅ Page loaded. Waiting 2 seconds for dynamic content...\n";
        sleep(2);
        
        return $html ? str_get_html($html) : null;
    }

    private function extractPropertyLinks(simple_html_dom $html): void {
        // file_put_contents('test.html', $html);
        // return;
        foreach ($html->find('.mask.pt-3.bg_color.text-center a') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/en/property/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $locationElement = $a->find('.location', 0);
                $locationText = $locationElement ? trim($locationElement->plaintext) : '';
                $this->propertyLinks[] = $fullUrl;
            }
        }
        $this->propertyLinks = array_unique($this->propertyLinks);

        // $result = $this->apiSender->getPropertyLinks("Marbella Realty Group", 178, 2050);
    
        // if ($result['success']) {
        //     $this->propertyLinks = array_unique($result["links"]);
        //     echo "🔗 Retrieved " . count($this->propertyLinks) . " property links from API\n";
        // } else {
        //     echo "❌ Failed to get property links: " . $result['error'] . "\n";
        //     echo "⚠️ Falling back to original scraping method if needed\n";
        //     $this->propertyLinks = []; // Initialize as empty array
        // }
    }

    // Rest of your existing methods remain the same...
    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
        $ownedBy = "Marbella Realty Group";
        $contactPerson = "Liam";
        $phone = "+34 672 26 1644";
        $email = "info@marbellarealtygroup.com";
        $status = "For Sale";

        

        // title
        $title = trim($html->find('.inmolink_pixel span', 0)->plaintext ?? '');

        // Extract the property description
        $descriptionElement = $html->find('.et_pb_tab_content span.descp', 0);

        // Initialize description
        $descriptionHtml = ''; 

        if ($descriptionElement) {
            $descriptionHtml = '<p>' . $descriptionElement->innertext . '</p>';
        }

        // Property excerpt
        $plainText = strip_tags($descriptionHtml);
        $translatedExcerpt = substr($plainText, 0, 300);

        // Reference
        $listing_id = '';
        $refElement = $html->find('div.grid_hl div', 1); // Second div after "Reference"
        if ($refElement) {
            $listing_id = trim($refElement->plaintext);
        }

        // Price and Currency
        $priceElement = $html->find('div.grid_hl span.Numeric_pricevalues', 0);

        $price = '';
        $currency = '';

        if ($priceElement) {
            try {
                // Get the full HTML content
                $priceHtml = $priceElement->innertext;
                
                // Remove the VAT span and its content
                $priceHtml = preg_replace('/<span[^>]*class="[^"]*vat[^"]*"[^>]*>.*?<\/span>/i', '', $priceHtml);
                
                // Convert HTML entities and clean up
                $priceText = html_entity_decode($priceHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $priceText = strip_tags($priceText);
                $priceText = trim($priceText);
                
                // Extract currency symbol with better regex
                if (preg_match('/([€$£¥₹])/', $priceText, $matches)) {
                    $currencySymbol = $matches[1];
                    
                    // Map symbol to currency code
                    $currencyMap = [
                        '€' => 'EUR',
                        '$' => 'USD',
                        '£' => 'GBP',
                    ];
                    
                    $currency = $currencyMap[$currencySymbol] ?? 'EUR';
                }

                // Extract only the numeric value
                if (preg_match('/[\d,]+/', $priceText, $priceMatches)) {
                    $price = (int)str_replace(',', '', $priceMatches[0]);
                }
                
            } catch (Exception $e) {
                echo "Error extracting price: " . $e->getMessage() . "\n";
                $price = 0;
                $currency = 'EUR';
            }
        }

        // Check if price extraction failed or resulted in zero/invalid price
        if (empty($price) || !is_numeric($price) || (int)$price <= 0) {
            echo "❌ Skipping property with invalid price. Extracted value: '$price'\n";
            $this->helpers->updatePostToDraft($url);
            return; 
        }

        // Property 
        $type = '';
        $type_arr = [];
        $propertyElements = $html->find('div.grid_hl div');
        foreach ($propertyElements as $i => $element) {
            if (trim($element->plaintext) == 'Property Type' && isset($propertyElements[$i + 1])) {
                $type = trim(strip_tags($propertyElements[$i + 1]->innertext));
                if($type) {
                    echo $type.'\n';
                    $type_arr = ['Apartment'];
                }
                break;
            }
        }

        // Check if type is empty and handle accordingly
        // if (empty($type) || count($type) > 0) {
        //     echo "❌ Skipping property with no property type: $type\n";
        //     return; // Exit the function without scraping
        // }

        if (empty($type_arr)) {
            echo "❌ Skipping property with no property type\n";
            $this->helpers->updatePostToDraft($url);
            return; // Exit the function without scraping
        }

        // // Bedrooms
        $bedrooms = '';
        $bedroomElements = $html->find('div.grid_hl div');
        foreach ($bedroomElements as $i => $element) {
            if (trim($element->plaintext) == 'Bedrooms' && isset($bedroomElements[$i + 1])) {
                $bedrooms = trim(strip_tags($bedroomElements[$i + 1]->innertext));
                break;
            }
        }

        // // Bathrooms
        $bathrooms = '';
        $bathroomElements = $html->find('div.grid_hl div');
        foreach ($bathroomElements as $i => $element) {
            if (trim($element->plaintext) == 'Bathrooms' && isset($bathroomElements[$i + 1])) {
                $bathrooms = trim(strip_tags($bathroomElements[$i + 1]->innertext));
                break;
            }
        }

        // Built Size
        $size = '';
        $size_prefix = '';
        $builtElements = $html->find('div.grid_hl div');
        foreach ($builtElements as $i => $element) {
            if (trim($element->plaintext) == 'Built' && isset($builtElements[$i + 1])) {
                $builtText = trim(strip_tags($builtElements[$i + 1]->innertext));
                
                // Extract numeric value and handle m² or sqm
                if (preg_match('/(\d+)\s*m/', $builtText, $matches)) {
                    $size = $matches[1];
                    $size_prefix = 'sqm';
                }
                break;
            }
        }


        //location details
        $detailsContainer = $html->find('div.grid_hl', 0);
        $details = $this->extractDetails($detailsContainer);


        // Corrected usage - safer approach
        $iframeElement = $html->find('iframe[src*="maps.google"]', 0);
        $coords = $this->extractLatLong($iframeElement ? $iframeElement->parent() : null);


        // Initialize an empty array to store image URLs
        $images = [];

        // Find the image gallery using the new structure
        $gallery = $html->find('#image-gallery li');

        if ($gallery && count($gallery) > 0) {
            foreach ($gallery as $index => $imgElement) {
                // Extract the image URL from the src attribute of the <img> tag
                $imgTag = $imgElement->find('img', 0);
                
                if ($imgTag) {
                    $imageUrl = $imgTag->getAttribute('src');
                    
                    // Remove the version parameter (?v=xxxxx)
                    $imageUrl = preg_replace('/\?v=\d+/', '', $imageUrl);
                    
                    // If image URL is valid, add it to the images array
                    if (!empty($imageUrl)) {
                        $images[] = $imageUrl;
                    }
                }
                
                // Stop after collecting 10 images
                if (count($images) >= 10) {
                    break;
                }
            }
        } 
        // Check if we found any images
        if (empty($images)) {
            echo "❌ Skipping property with no images \n";
            $this->helpers->updatePostToDraft($url);
            return; // Exit the function without scraping
        }

        $features = [];

        // Find all li.features elements
        $featuresBlocks = $html->find('li.features');

        if ($featuresBlocks) {
            foreach ($featuresBlocks as $block) {
                // Check if this block has h4 with "Features" text
                $h4 = $block->find('h4', 0);
                
                if ($h4 && trim($h4->plaintext) === 'Features') {
                    // Found the Features block, now extract all <p> tags
                    $paragraphs = $block->find('p');
                    
                    foreach ($paragraphs as $paragraph) {
                        // Extract the text, removing any HTML tags
                        $text = trim(strip_tags($paragraph->plaintext));
                        
                        // Add the text to the features array if it's not empty
                        if ($text !== '') {
                            $features[] = $text;
                        }
                    }
                    
                    // Break after finding the Features section
                    break;
                }
            }
        }

        $address_data = $this->helpers->getLocationDataByCoords($coords['latitude'], $coords['longitude']) ?? [];

        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $this->translateHtmlPreservingTags($descriptionHtml),
            "property_excerpt" => $translatedExcerpt,
            "price" => $price,
            "currency" => $currency,
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $coords['location'],
            "bedrooms" => $bedrooms,
            "bathrooms" => $bathrooms,
            "size" => $size,
            "size_prefix" => $size_prefix,
            "property_type" => $type_arr,
            "property_status" => [$status],
            // "property_address" => $details['address'],     
            // "property_area" => $details['location'],        
            // "city" => '',                 
            // "state" => $details['area'],                    
            // "country" => $details['country'], 
            // "zip_code" => "",
            "property_address" => $address_data['address'],
            "property_area" => "",
            "city" => $address_data['city'],
            "state" => $address_data['state'],
            "country" => $address_data['country'],
            "zip_code" => $address_data['postal_code'],

            "latitude" => $coords['latitude'],
            "longitude" => $coords['longitude'],
            "listing_id" => 'MRG_'.$listing_id,
            "agent_id" => "150",
            "agent_display_option" => "agent_info",
            "mls_id" => "",
            "office_name" => "",
            "video_url" => "",
            "virtual_tour" => "",
            "images" => $images,
            "property_map" => "1",
            "property_year" => "",
            "additional_features" => $features,
            "confidential_info" => [
                [
                    "fave_additional_feature_title" => "Owned by",
                    "fave_additional_feature_value" => $ownedBy
                ],
                [
                    "fave_additional_feature_title" => "Website",
                    "fave_additional_feature_value" => $url,
                ],
                [
                    "fave_additional_feature_title" => "Contact Person",
                    "fave_additional_feature_value" => $contactPerson
                ],
                [
                    "fave_additional_feature_title" => "Phone",
                    "fave_additional_feature_value" => $phone
                ],
                [
                    "fave_additional_feature_title" => "Email",
                    "fave_additional_feature_value" => $email
                ]
            ]
        ];
    }

    
    private function extractDetails($html): array {
        // Extract Location and Area from grid_hl structure
        $location = '';
        $area = '';
        $country = 'Spain'; // Default for Costa del Sol properties

        $gridElements = $html->find('div');
        
        foreach ($gridElements as $i => $element) {
            $elementText = trim($element->plaintext);
            
            // Extract Location
            if ($elementText == 'Location' && isset($gridElements[$i + 1])) {
                $location = trim(strip_tags($gridElements[$i + 1]->innertext));
            }
            
            // Extract Area (Province)
            if ($elementText == 'Area' && isset($gridElements[$i + 1])) {
                $area = trim(strip_tags($gridElements[$i + 1]->innertext));
            }
            
            // Break early if we have both values
            if ($location && $area) {
                break;
            }
        }

        // Classify location types
        $locationType = $this->classifyLocationType($location, $area);
        $areaType = $this->classifyLocationType($area);

        // Create full address
        $address = trim($location . ', ' . $area . ', ' . $country);

        return [
            'location' => $location,
            'location_type' => $locationType,
            'area' => $area,
            'area_type' => $areaType,
            'address' => $address,
            'country' => $country
        ];
    }

    // Add the classifyLocationType method to your class
    private function classifyLocationType($location, $parentLocation = '') {
        // Convert to lowercase for comparison
        $location = strtolower(trim($location));
        $parentLocation = strtolower(trim($parentLocation));
        
        // Define known provinces in Spain
        $provinces = [
            'málaga', 'malaga', 'madrid', 'barcelona', 'valencia', 'sevilla', 'alicante', 
            'murcia', 'cádiz', 'cadiz', 'granada', 'almería', 'almeria', 'córdoba', 
            'cordoba', 'jaén', 'jaen', 'huelva'
        ];
        
        // Define known municipalities
        $municipalities = [
            'manilva', 'marbella', 'estepona', 'fuengirola', 'torremolinos', 'benalmádena',
            'benalmadena', 'mijas', 'casares', 'benahavís', 'benahavis'
        ];
        
        // Define known areas/districts/neighborhoods
        $areas = [
            'la cala golf', 'la cala de mijas', 'puerto banús', 'puerto banus', 
            'nueva andalucía', 'nueva andalucia', 'golden mile', 'san pedro de alcántara',
            'san pedro de alcantara', 'sabinillas', 'puerto de la duquesa', 'sotogrande',
            'sierra blanca', 'cascada de camojan', 'nagueles', 'nagüeles', 'la zagaleta'
        ];
        
        // Classification logic
        if (in_array($location, $provinces)) {
            return 'province';
        } elseif (in_array($location, $municipalities)) {
            return 'municipality';
        } elseif (in_array($location, $areas)) {
            return 'area';
        } else {
            // If parent location is a known province, this is likely a municipality
            if (in_array($parentLocation, $provinces)) {
                return 'municipality';
            }
            // Default fallback based on common patterns
            return 'area';
        }
    }    

    private function extractLatLong($mapElement): array {
        // Return empty if no element provided
        if (!$mapElement) {
            return ['location' => '', 'latitude' => '', 'longitude' => ''];
        }
        
        // Extract from Google Maps iframe
        $iframe = $mapElement->find('iframe[src*="maps.google"]', 0);
        
        if ($iframe) {
            $src = $iframe->getAttribute('src');
            
            // Extract the 'q' parameter from the iframe src
            if (preg_match('/[?&]q=([^&]+)/', $src, $matches)) {
                $query = urldecode($matches[1]);
                
                // Get coordinates using PositionStack API
                $coordinates = $this->getCoordinatesFromQuery($query);
                
                if ($coordinates) {
                    return [
                        'location' => $coordinates['latitude'] . ', ' . $coordinates['longitude'],
                        'latitude' => (string)$coordinates['latitude'],
                        'longitude' => (string)$coordinates['longitude']
                    ];
                }
            }
        }

        return ['location' => '', 'latitude' => '', 'longitude' => ''];
    }

    // PositionStack geocoding with your API key
    private function getCoordinatesFromQuery($query) {
        $apiKey = '04c7be9907947f8bdc0867d28854748b';
        
        $encodedQuery = urlencode(trim($query));
        $url = "http://api.positionstack.com/v1/forward?access_key={$apiKey}&query={$encodedQuery}&limit=1";
        
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
                return [
                    'latitude' => (float)$result['latitude'],
                    'longitude' => (float)$result['longitude'],
                    'formatted_address' => $result['label']
                ];
            } else {
                error_log("No results from PositionStack for query: {$query}");
            }
            
        } catch (Exception $e) {
            error_log("PositionStack API error: " . $e->getMessage());
        }
        
        return null;
    }

    private function translateHtmlPreservingTags(string $html): string {
        $html = "<div>$html</div>";
        $translated = preg_replace_callback('/>([^<>]+)</', function ($matches) {
            $text = trim($matches[1]);
            if ($text === '') return '><';
            $translatedText = $text;
            return ">$translatedText<";
        }, $html);

        return preg_replace('/^<div>|<\/div>$/', '', $translated);
    }

    private function saveToJson(string $filename): void {
        file_put_contents(
            $filename,
            json_encode($this->scrapedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}