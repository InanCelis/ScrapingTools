<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class LuxuryEstateTurkey {
    private string $baseUrl = "https://luxuryestateturkey.com";
    private string $foldername = "LuxuryEstateTurkey";
    private string $filename = "Properties4.json";
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
        for ($page = 66; $page <= $pageCount; $page++) {
            $url = $this->baseUrl . "/en/real-estate/turkey?building_type%5B0%5D=1&building_type%5B1%5D=3&building_type%5B2%5D=4&building_type%5B3%5D=7&building_type%5B4%5D=8&building_type%5B5%5D=9&order=from_expensive_to_cheap&p={$page}";
            
            echo "📄 Fetching page $page: $url\n";

            // $html = file_get_html($url);
            $html = $this->helpers->getHtmlWithJS($url);
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

        foreach ($html->find('.card.bg-transparent.border-0 .row .col-lg-12 a.h3.fs-3') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/en/real-estate/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $locationElement = $a->find('.h3.fs-3', 0);
                $locationText = $locationElement ? trim($locationElement->plaintext) : '';
                $this->propertyLinks[] = $fullUrl;
            }
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

   

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
       
        $ownedBy = "Luxury Estate Turkey";
        $contactPerson = "Ibrahim Boztoz";
        $phone = "+90 5050 140101";
        $email = "office@luxuryestateturkey.com";
        

        // title
        $title = trim($html->find('h1.py-4', 0)->plaintext ?? '');
        if(empty($title)) {
            echo "❌ Skipping property with invalid setup of html\n ";
            return; 
        }

        // Extract the description
        $descriptionElement = $html->find('.card-body.px-4.px-md-9.py-3 .fw-semibold.fs-5', 0);

        // Initialize description as an empty string
        $descriptionHtml = '';

        if ($descriptionElement) {
            // Create new element with same tag but no classes
            $tagName = $descriptionElement->tag;
            $innerContent = $descriptionElement->innertext;
            $descriptionHtml = "<{$tagName}>{$innerContent}</{$tagName}>";
        }

        // Property excerpt
        $plainText = strip_tags($descriptionHtml);
        // Remove excessive whitespace, newlines, and tabs
        $cleanText = preg_replace('/\s+/', ' ', $plainText);
        // Trim leading and trailing whitespace
        $cleanText = trim($cleanText);
        // Create excerpt
        $translatedExcerpt = substr($cleanText, 0, 300);

        // Price and Currency
        $priceElement = $html->find('span.fw-bolder.text-primary.fs-1', 0);

        $price = '';
        $currency = '';

        if ($priceElement) {
            try {
                // Get the price text and ensure UTF-8 encoding
                $priceText = trim($priceElement->plaintext);
                
                // Get first character using multibyte function
                $firstChar = mb_substr($priceText, 0, 1, 'UTF-8');
                
                // Determine currency based on first character
                if ($firstChar === '€') {
                    $currency = 'EUR';
                } elseif ($firstChar === '$') {
                    $currency = 'USD';
                } else {
                    // Fallback: check if it contains euro symbol anywhere
                    if (mb_strpos($priceText, '€', 0, 'UTF-8') !== false) {
                        $currency = 'EUR';
                    } else {
                        $currency = 'EUR'; // Default to EUR
                    }
                }

                // Extract numeric value
                $numericPrice = preg_replace('/[^0-9,]/', '', $priceText);
                $price = (int)str_replace(',', '', $numericPrice);
                
                // echo "✅ Price extracted: $price $currency (detected: $firstChar)\n";
                
            } catch (Exception $e) {
                echo "Error extracting price: " . $e->getMessage() . "\n";
                $price = 0;
                $currency = 'EUR';
            }
        }
        

        $roomsElement = $html->find('div span.fs-6.fw-bold', 0);

        $bedrooms = 0;
        $property_type = [];

        if ($roomsElement) {
            $text = trim($roomsElement->plaintext); // "1+1, Apartment"
            
            $parts = explode(',', $text);
            $roomsConfig = trim($parts[0]);      // "1+1"
            $type_extracted = trim($parts[1]);
            $allowedTypes = ['Townhouse', 'Villa', 'Apartment', 'House', 'Detached house', 'Penthouse', 'Hotel'];
             // Check if property type is allowed (case insensitive comparison)
            if (!in_array(strtolower($type_extracted), array_map('strtolower', $allowedTypes))) {
                echo "❌ Skipping property of type: $type_extracted\n";
                return; // Exit the function without scraping
            }


            if($type_extracted == "Penthouse") {
                $property_type[] = 'Apartment';
            } else if($type_extracted == "Detached house" || $type_extracted == "Townhouse") {
                $property_type[] = 'House';
            } else {
                $property_type[] = $type_extracted; 
            }
            
            // Add the numbers: 1+1 = 2
            $numbers = explode('+', $roomsConfig);
            $bedrooms = (int)$numbers[0] + (int)$numbers[1]; // 1 + 1 = 2
            
        }

        if (empty($property_type)) {
            echo "❌ Skipping property with no property type\n";
            return; // Exit the function without scraping
        }

        // Extract size, size prefix, and bath number
        $size = '';
        $size_prefix = '';
        $bathrooms = 0;

        // Find all p elements with fw-bold class
        $boldElements = $html->find('p.fs-6.fw-bold');

        foreach ($boldElements as $element) {
            $text = trim($element->plaintext);
            
            // Extract size (45m²)
            if (preg_match('/(\d+)m²/', $text, $matches)) {
                $size = $matches[1]; // "45"
                $size_prefix = 'sqm';
            }
            
            // Extract bath number (1 Bath)
            if (preg_match('/(\d+)\s+Bath/', $text, $matches)) {
                $bathrooms = (int)$matches[1]; // 1
            }
        }

        // Extract latitude and longitude
        $lat = '';
        $lng = '';
        $location = '';
        $address_data = [];

        // Find the script tag containing the map initialization
        $scriptElements = $html->find('script');

        foreach ($scriptElements as $script) {
            $scriptContent = $script->innertext;
            
            // Look for lat and lng values in the script
            if (preg_match('/lat:\s*([\d.-]+)/', $scriptContent, $latMatches)) {
                $lat = (float)$latMatches[1];
            }
            
            if (preg_match('/lng:\s*([\d.-]+)/', $scriptContent, $lngMatches)) {
                $lng = (float)$lngMatches[1];
            }
            
            // Break if we found both values
            if ($lat && $lng) {
                $location = $lat.', '.$lng;
                // $address_data = $this->helpers->getLocationDataByCoords($lat, $lng) ?? [];
                break;
            }
        }
        
        $breadcrumbLinks = $html->find('ul.breadcrumb li a');

        $country = isset($breadcrumbLinks[1]) ? trim($breadcrumbLinks[1]->plaintext) : 'Turkey';
        $state = isset($breadcrumbLinks[2]) ? trim($breadcrumbLinks[2]->plaintext) : '';
        $city = isset($breadcrumbLinks[3]) ? trim($breadcrumbLinks[3]->plaintext) : '';
        // $area = isset($breadcrumbLinks[4]) ? trim($breadcrumbLinks[4]->plaintext) : '';
        
        // Combine into address variable, filtering out empty values
        $addressParts = array_filter([$city, $state, $country]);
        $address = implode(', ', $addressParts);

    
        $spans = $html->find('.flex-grow-1 span');
        $listing_id = '';
        foreach ($spans as $span) {
            if (strpos($span->plaintext, 'Property id') !== false) {
                $parent = $span->parent();
                $valueSpans = $parent->find('span.text-gray-600');
                if (!empty($valueSpans)) {
                    $listing_id = trim($valueSpans[0]->plaintext);
                }
                break;
            }
        }

        $features = [];
        // Look for the features section by finding the parent container
        $featuresContainer = $html->find('div.row.row-cols-2.row-cols-md-3.gy-4.gx-6.mb-5', 0);

        if ($featuresContainer) {
            // Find all feature items within this specific container
            $featureItems = $featuresContainer->find('div.col.d-flex.align-items-center');
            
            foreach ($featureItems as $item) {
                // Get the span that contains the feature text
                $featureSpan = $item->find('span.fs-5.fw-bold.text-gray-900', 0);
                
                if ($featureSpan) {
                    $text = trim(strip_tags($featureSpan->plaintext));
                    
                    if ($text !== '') {
                        $features[] = $text;
                    }
                }
            }
        }
        $images = [];

        // Find the specific image gallery container by ID
        $galleryContainer = $html->find('#modal_property_photos_widget', 0);

        if ($galleryContainer) {
            // Find all anchor tags with glightbox class within this container
            $imageLinks = $galleryContainer->find('a.glightbox');
            
            if ($imageLinks && count($imageLinks) > 0) {
                foreach ($imageLinks as $index => $linkElement) {
                    // Get the href attribute which contains the full-size image URL
                    $imageUrl = $linkElement->getAttribute('href');
                    
                    if ($imageUrl) {
                        // Remove version parameters (?v=1)
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
        }

        // Check if we found any images
        if (empty($images)) {
            echo "❌ Skipping property with no images \n";
            return; // Exit the function without scraping
        }

        
        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $this->helpers->translateHtmlPreservingTags($descriptionHtml),
            "property_excerpt" => $translatedExcerpt,
            "price" => $price,
            "currency" => $currency,
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $location,
            "bedrooms" => $bedrooms,
            "bathrooms" => $bathrooms,
            "size" => $size,
            "size_prefix" => $size_prefix,
            "property_type" => $property_type,
            "property_status" => ["For Sale"],
            "property_address" => $address,
            "property_area" => "",
            "city" => $city,
            "state" => $state,
            "country" => $country,
            "zip_code" => "",
            "latitude" => $lat,
            "longitude" => $lng,
            "listing_id" => 'LET-'.$listing_id,
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


    private function saveToJson(string $filename): void {
        file_put_contents(
            $filename,
            json_encode($this->scrapedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}

