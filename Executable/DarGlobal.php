<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class DarGlobal {
    private string $baseUrl = "https://darglobal.co.uk";
    private string $foldername = "DarGlobal";
    private string $filename = "Properties.json";
    private array $propertyLinks = [];
    private array $scrapedData = [];
    private ApiSender $apiSender;
    private ScraperHelpers $helpers;
    private int $successCreated;
    private int $successUpdated;
    private bool $enableUpload = true;
    private bool $testingMode = false;

    public function __construct() {
        // Initialize the ApiSender with your actual API URL and token
        $this->apiSender = new ApiSender(true);
        $this->helpers = new ScraperHelpers();
        $this->successCreated = 0;
        $this->successUpdated = 0;
    }

    public function run(int $pageCount = 1, int $limit = 0): void {
        $folder = __DIR__ . '/../ScrapeFile/'.$this->foldername;
        $outputFile = $folder . '/'.$this->filename;
        if($this->testingMode) {
            $htmlTest =  $folder . '/Test.html';
        }
        

        // Create the folder if it doesn't exist
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Start a fresh JSON array
        file_put_contents($outputFile, "[");

        $propertyCounter = 0;
        for ($page = 1; $page <= $pageCount; $page++) {0;
            $url = $this->baseUrl . "/projects";
            
            echo "📄 Fetching page $page: $url\n";

            $html = file_get_html($url);
            if (!$html) {
                echo "⚠️ Failed to load page $page. Skipping...\n";
                continue;
            }
            $this->extractPropertyLinks($html);
        }

        // Deduplicate array of arrays
        $this->propertyLinks = array_map("unserialize", array_unique(array_map("serialize", $this->propertyLinks)));
        if ($limit > 0) {
            $this->propertyLinks = array_slice($this->propertyLinks, 0, $limit);
        }
        $countLinks = 1;
        foreach ($this->propertyLinks as $url) {
            echo "URL ".$countLinks++." 🔍 Scraping: $url\n";
            $propertyHtml = file_get_html($url);
            if ($propertyHtml) {
                $this->scrapedData = []; // Clear for fresh
                
                if($this->testingMode) {
                    file_put_contents($htmlTest, $propertyHtml);
                    return;
                }

                $this->scrapePropertyDetails($propertyHtml, $url);

                if (!empty($this->scrapedData[0])) {
                    $jsonEntry = json_encode($this->scrapedData[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    file_put_contents($outputFile, ($propertyCounter > 0 ? "," : "") . "\n" . $jsonEntry, FILE_APPEND);
                    $propertyCounter++;

                    // Send the property data via the ApiSender

                    if($this->enableUpload) {
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
        if($this->testingMode) {
            // file_put_contents('test.html', $html);
            // return;
        }
        foreach ($html->find('.projectCardWrap_tabCardContentList__LXZBY a') as $a) {
            $href = $a->href ?? '';
            if (!empty($href)) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $this->propertyLinks[] = $fullUrl;
            }
        }

        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
       
        $ownedBy = "DarGlobal PLC";
        $contactPerson = "Tahera Zaman";
        $phone = "+44 7369 249400";
        $email = "TZaman@darglobal.co.uk";
        

        $script = $html->find('script#__NEXT_DATA__', 0);
        $jsonData = json_decode($script->innertext, true);
        $propertyListing = $jsonData['props']['pageProps']['projectDetailsData']['attributes'] ?? null;
        $listing_id = $jsonData['props']['pageProps']['projectDetailsData']['id'] ?? '';

        $title = $propertyListing['title'] ?? null;
        if(empty($title)) {
            echo "❌ Skipping property with invalid setup of html\n ";
            return; 
        }

        $description = $propertyListing['ProjectDetails']['AboutProject']['description'] ?? $propertyListing['ProjectDetails']['AboutProject']['richDescription'] ?? '';

        // property_excerpt
        $plainText = trim(strip_tags($description));
        $translatedExcerpt = substr($plainText, 0, 300);

        $locData = $propertyListing['ProjectDetails']['loaction']['locationDetailsWithImages'] ?? [];
        $coords = $this->extractLatLong($locData);


        $address_data = $this->helpers->getLocationDataByCoords($coords['latitude'], $coords['longitude']) ?? [];
       
        if(empty($address_data['country'])) {
            $address_part = $locData['location'];
            $address_data = $this->helpers->getCoordinatesFromQuery($address_part);
        }
        // Define allowed types
        $type_allowed = ['Hotel', 'Condo', 'Villa', 'Apartment', 'Penthouse'];

        // Get AboutDetailsType array
        $aboutDetails = $propertyListing['ProjectDetails']['AboutProject']['AboutDetailsType'] ?? [];

        // Extract property types
        $property_type = [];
        $size = "";
        $size_prefix = "";
        $status = [];
        foreach ($aboutDetails as $detail) {
            $name = strtoupper($detail['name'] ?? '');
            $value = $detail['value'] ?? '';
            
            // Check both PROPERTY TYPE and UNIT TYPE fields
            if ($name === 'PROPERTY TYPE' || $name === 'UNIT TYPE') {
                // Check each allowed type against the value
                foreach ($type_allowed as $allowed_type) {
                    if (stripos($value, $allowed_type) !== false) {
                        if (!in_array($allowed_type, $property_type)) {
                            $property_type[] = $allowed_type;
                        }
                    }
                }
            }

            // Look for area-related fields
            if (stripos($name, 'AREA') !== false || stripos($name, 'SIZE') !== false) {
                // Define allowed size prefixes
                $size_prefix_allowed = ['sqm', 'sqft'];
                
                
                // Check which prefix is mentioned in the field name or value
                foreach ($size_prefix_allowed as $prefix) {
                    if (stripos($name, strtoupper($prefix)) !== false || stripos($value, $prefix) !== false) {
                        $size_prefix = $prefix;
                        break;
                    }
                }
                
                // Extract the area size (numbers and range)
                $size = $value;
                
                // Clean up the area size by removing the prefix if it's in the value
                if ($size_prefix) {
                    $size = preg_replace('/\b' . preg_quote($size_prefix, '/') . '\b/i', '', $size);
                    $size = trim($size);
                }
                
                break;
            }

            if ($name === 'STATUS') {

                if ($value == "Sold Out") {
                    echo "❌ Skipping property with status {$value} \n";
                    return; 
                }

                // Add current status if not empty
                if (!empty($value)) {
                    $status[] = $value;  // This won't execute if $value is empty
                }
                
                // Add "For Sale" if status is "Under Development" or empty
                if ($value == "Under Development" || $value == "Under Development ") {
                    $status[] = "For Sale";  // This WILL execute if $value is empty
                }
            }
        }
        if(empty($status)) {
            $status[] = "For Sale";
        }



        // If no matches found, try some additional mapping
        if (empty($property_type)) {
            foreach ($aboutDetails as $detail) {
                $name = strtoupper($detail['name'] ?? '');
                $value = strtolower($detail['value'] ?? '');
                
                if ($name === 'PROPERTY TYPE' || $name === 'UNIT TYPE') {
                    // Additional mappings
                    if (strpos($value, 'residential') !== false || strpos($value, 'residence') !== false) {
                        if (!in_array('Apartment', $property_type)) {
                            $property_type[] = 'Apartment';
                        }
                    }
                    if (strpos($value, 'tower') !== false || strpos($value, 'building') !== false) {
                        if (!in_array('Condo', $property_type)) {
                            $property_type[] = 'Condo';
                        }
                    }
                }
            }
        }

        if (empty($property_type)) { 
            foreach ($type_allowed as $allowed_type) {
                // Use word boundary to avoid partial matches
                if (preg_match('/\b' . preg_quote($allowed_type, '/') . 's?\b/i', $description)) {
                    if (!in_array($allowed_type, $property_type)) {
                        $property_type[] = $allowed_type;
                    }
                }
            }
        }


        // Extract bedroom information
        $bedrooms = 0;

        foreach ($aboutDetails as $detail) {
            $name = strtoupper($detail['name'] ?? '');
            $value = $detail['value'] ?? '';
            
            // Look for UNIT TYPE field
            if ($name === 'UNIT TYPE' || $name === "UNITS" || $name === "PROPERTY TYPE") {
                // Only extract numbers if "bedroom" or "bedrooms" is mentioned
                if (stripos($value, 'bedroom') !== false) {
                    // Extract ALL numbers from the unit type description
                    preg_match_all('/\d+/', $value, $matches);
                    
                    if (!empty($matches[0])) {
                        // Convert to integers and remove duplicates
                        $numbers = array_map('intval', $matches[0]);
                        $numbers = array_unique($numbers);
                        sort($numbers);
                        
                        // Format output based on count
                        if (count($numbers) > 1) {
                            // $bedrooms = min($numbers) . '-' . max($numbers);
                            $bedrooms = max($numbers);
                        } else {
                            $bedrooms = (string)$numbers[0];
                        }
                    }
                    break; // Stop after finding the first match with "bedroom"
                }
            }
        }


        // Images - Correct path based on the JSON structure
        $mediaFiles = $propertyListing['ProjectDetails']['PhotoGallery']['galleryList'] ?? [];

        // Process images - extract original URLs and limit to 10
        $images = [];
        foreach ($mediaFiles as $media) {
            $img_url = $media['Image']['data'][0]['attributes']['url'] ?? '';
            if (!empty($img_url)) {
                $images[] = $img_url;
                // Stop when we have 10 images
                if (count($images) >= 10) {
                    break;
                }
            }
        }

        // Ensure exactly 10 images or less
        $images = array_slice($images, 0, 10);
        if (empty($images)) {
            echo "❌ Skipping property with no images \n";
            return; 
        }

        $features = [];
        $feature_lists = $propertyListing['ProjectDetails']['Amenities']['amenitiesList'] ?? [];
        foreach($feature_lists as $feature) {
            $value = $feature['name'];
            if (!empty($value)) {
                $features[] = $value;
            }
        }
        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $this->helpers->translateHtmlPreservingTags($description),
            "property_excerpt" => $translatedExcerpt,
            "price" => "",
            "currency" => "",
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $coords['location'],
            "bedrooms" => $bedrooms,
            "bathrooms" => "",
            "size" => $size,
            "size_prefix" => $size_prefix,
            "property_type" => $property_type,
            "property_status" => $status,
            "property_address" => $address_data['address'],
            "property_area" => "",
            "city" => $address_data['city'],
            "state" => $address_data['state'],
            "country" => $address_data['country'],
            "zip_code" => $address_data['postal_code'],
            "latitude" => $coords['latitude'],
            "longitude" => $coords['longitude'],
            "listing_id" => 'DARG-'.$listing_id,
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

    private function extractLatLong($jsonData): array {
        // Look for iframe with class "map__iframe"
        if ($jsonData) {
            return [
                    'location' => $jsonData['lat']. ', ' . $jsonData['long'],
                    'latitude' => $jsonData['lat'],
                    'longitude' => $jsonData['long']
            ];
        }
        // Fallback or not found
        return ['location' => '', 'latitude' => '', 'longitude' => ''];
    }

    private function saveToJson(string $filename): void {
        file_put_contents(
            $filename,
            json_encode($this->scrapedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}

