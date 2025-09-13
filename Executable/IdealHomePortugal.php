<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';

class IdealHomePortugal {
    private string $baseUrl = "https://www.idealhomesportugal.com";
    private array $propertyLinks = [];
    private array $scrapedData = [];
    private ApiSender $apiSender;
    private int $successUpload;

    public function __construct() {
        // Initialize the ApiSender with your actual API URL and token
        $this->apiSender = new ApiSender(true);
        $this->successCreated = 0;
        $this->successUpdated = 0;
    }

    public function run(int $pageCount = 1, int $limit = 0): void {
        $folder = __DIR__ . '/../ScrapeFile/IdealHome';
        $outputFile = $folder . '/Lagos.json';
        // $htmlTest =  $folder . '/Test.html';

        // Create the folder if it doesn't exist
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Start a fresh JSON array
        file_put_contents($outputFile, "[");

        $propertyCounter = 0;
        $pages = 0;
        for ($page = 1; $page <= $pageCount; $page++) {0;
            // $url = $this->baseUrl . "/properties/house-type?page={$page}&sort_by=price-desc&web_page=properties";
            $url = $this->baseUrl . "/property-for-sale/lagos?location=Lagos&price_from=0&price_to=10000000&page={$page}";
            
            echo "📄 Fetching page $page: $url\n";

            $html = file_get_html($url);
            if (!$html) {
                echo "⚠️ Failed to load page $page. Skipping...\n";
                continue;
            }
            $pages +=24;
            $this->extractPropertyLinks($html);
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

        foreach ($this->propertyLinks as $data) {
            echo "URL ".$countLinks++." 🔍 Scraping: {$data['url']}\n";
            $propertyHtml = file_get_html($data['url']);
            if ($propertyHtml) {
                $this->scrapedData = []; // Clear for fresh 
                // file_put_contents($htmlTest, $propertyHtml);
                // return;
                $this->scrapePropertyDetails($propertyHtml, $data);
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
                    // echo $jsonEntry;
                }
            }
        }

        // Close the JSON array
        file_put_contents($outputFile, "\n]", FILE_APPEND);
        echo "✅ Scraping completed. Output saved to {$outputFile}\n";
        echo "✅ Properties Created: {$this->successCreated}\n";
        echo "✅ Properties Updated: {$this->successUpdated}\n";
    }

    private function getHtml(string $url): ?simple_html_dom {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_FOLLOWLOCATION => true
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        return $html ? str_get_html($html) : null;
    }

    private function extractPropertyLinks(simple_html_dom $html): void {
        foreach ($html->find('.col-lg-9 a') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/property/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                // $loc = $a->find('.location', 0);
                // $loc = trim(strip_tags($loc->innertext));

                // Extract location text and parse components
                $locationElement = $a->find('.location', 0);
                $locationText = $locationElement ? trim($locationElement->plaintext) : '';
                $this->propertyLinks[] = [
                    "url" => $fullUrl,
                    "address" => $locationText
                ];
            }
            
        }
        // Deduplicate array of arrays
        $this->propertyLinks = array_map("unserialize", array_unique(array_map("serialize", $this->propertyLinks)));
    }

    private function scrapePropertyDetails(simple_html_dom $html, array $data): void {
        // echo $data['address']."\n";
        // Get property type first to check if we should proceed
        $script = $html->find('script#__NEXT_DATA__', 0);
        $jsonData = json_decode($script->innertext, true);
        $propertyListing = $jsonData['props']['pageProps']['propertyListing'];
        
        // Clean and normalize the property type
        $type = trim(str_replace("\r", '', $propertyListing['type'] ?? ''));
        $type = preg_replace('/\s+/', ' ', $type); // Remove extra whitespace
        
        $status = $propertyListing['saleFlag'];

        // List of allowed property types (case insensitive)
        $allowedTypes = ['Townhouse', 'Villa', 'Apartment', 'Penthouse'];
        $allowedStatuses = ['Available', 'Recently_Reduced'];
        
        // Check if property type is allowed (case insensitive comparison)
        if (!in_array(strtolower($type), array_map('strtolower', $allowedTypes))) {
            echo "❌ Skipping property of type: $type\n";
            return; // Exit the function without scraping
        }

        if (!in_array(strtolower($status), array_map('strtolower', $allowedStatuses))) {
            echo "❌ Skipping property with status: $status\n";
            return; // Exit the function without scraping
        }

        //======================================================================//

        $ownedBy = "Ideal Homes Portugal";
        $contactPerson = "Ideal Homes Portugal";
        $phone = "+1 800 435 0796";
        $email = "info@idealhomesportugal.com";

        $coords = $this->extractLatLong($propertyListing);
        // title 
        $title = $propertyListing['title'] ?? '';

        //description
        $descriptionHtml = $propertyListing['description'] ?? '';
        // property_excerpt
        $plainText = trim(strip_tags($descriptionHtml));
        $translatedExcerpt = substr($plainText, 0, 300);

        // price
        $price = $propertyListing['price'] ?? '';

        // Check if price extraction failed or resulted in zero/invalid price
        if (empty($price) || !is_numeric($price) || (int)$price <= 0) {
            echo "❌ Skipping property with invalid price. Extracted value: '$price'\n";
            return; 
        }
        //bedroom
        $bedroom = isset($propertyListing['beds']) ? (int)$propertyListing['beds'] : 0;

        //bath
        $bathroom = isset($propertyListing['baths']) ? (int)$propertyListing['baths'] : 0;

        //size
        $area_size = $propertyListing['area'];


        //Addresses
        $locationParts = array_map('trim', explode(',', $data['address']));
        $city = $locationParts[0] ?? '';
        $state = $locationParts[1] ?? '';
        $country = $locationParts[2] ?? 'Portugal'; // Default to Portugal if not specified

        // Images
        $mediaFiles = $propertyListing['media_files'] ?? [];

        // Process images - extract original URLs, remove parameters, and limit to 10
        $images = [];
        foreach ($mediaFiles as $media) {
            if (isset($media['original'])) {
                // Remove all parameters after ? including the ?
                $cleanUrl = preg_replace('/\?.*$/', '', $media['original']);
                
                // Only add if not empty and not already in array
                if (!empty($cleanUrl) && !in_array($cleanUrl, $images)) {
                    $images[] = "https://api.idealhomesportugal.com/media".$cleanUrl;
                    
                    // Stop when we have 10 images
                    if (count($images) >= 10) {
                        break;
                    }
                }
            }
        }

        // If you want to ensure you have exactly 10 images (even if some were duplicates)
        $images = array_slice($images, 0, 10);

        //additional features
        $features = $propertyListing['features'] ?? [];
        // listing id
        $listing_id = $propertyListing['reference'] ?? '';
        //video url
        $video_url = $propertyListing['video_url'] ?? "";
    
        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $this->translateHtmlPreservingTags($descriptionHtml),
            "property_excerpt" => $translatedExcerpt,
            "price" => $price,
            "currency" => "EUR",
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $coords['location'],
            "bedrooms" => $bedroom,
            "bathrooms" => $bathroom,
            "size" => $area_size,
            "size_prefix" => "sqm",
            "property_type" => [$type],
            "property_status" => ["For Sale"],
            "property_address" => $data['address'],
            "property_area" => "",
            "city" => $city,
            "state" => $state,
            "country" => "Portugal",
            "zip_code" => "",
            "latitude" => $coords['latitude'],
            "longitude" => $coords['longitude'],
            "listing_id" => $listing_id,
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
                    "fave_additional_feature_value" => "{$data['url']}"
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


    private function extractLatLong($jsonData): array {
        // Look for iframe with class "map__iframe"
        if ($jsonData) {
            return [
                    'location' => $jsonData['latitude']. ', ' . $jsonData['longitude'],
                    'latitude' => $jsonData['latitude'],
                    'longitude' => $jsonData['longitude']
            ];
        }
        // Fallback or not found
        return ['location' => '', 'latitude' => '', 'longitude' => ''];
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
