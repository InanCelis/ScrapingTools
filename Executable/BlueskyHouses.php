<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';

class BlueskyHouses {
    private string $baseUrl = "https://www.bluesky-houses.com/";
    private array $propertyLinks = [];
    private array $scrapedData = [];
    private ApiSender $apiSender;
    private int $successCreated;
    private int $successUpdated;

    public function __construct() {
        // Initialize the ApiSender with your actual API URL and token
        $this->apiSender = new ApiSender(true);
        $this->successCreated = 0;
        $this->successUpdated = 0;
    }

    public function run(int $pageCount = 1, int $limit = 0): void {
        $folder = __DIR__ . '/../ScrapeFile/BlueskyHouses';
        $outputFile = $folder . '/ApartmentV3.json';
        // $htmlTest =  $folder . '/Test.html';

        // Create the folder if it doesn't exist
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Start a fresh JSON array
        file_put_contents($outputFile, "[");

        $propertyCounter = 0;
        for ($page = 79; $page <= $pageCount; $page++) {0;
            $url = $this->baseUrl . "properties/search:for-sale:type-apartment:price_from-150000?page={$page}";
            
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
        // file_put_contents('test.html', $html);
        // return;
        foreach ($html->find('a.property_listing') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, 'properties/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $locationElement = $a->find('.location', 0);
                $locationText = $locationElement ? trim($locationElement->plaintext) : '';
                $this->propertyLinks[] = $fullUrl;
            }
            
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
       
        $ownedBy = "FAE BlueSky Houses Ltd.";
        $contactPerson = "Elena Davison";
        $phone = "+357 269 38900";
        $email = "info@bluesky-houses.com";
        $type = "Apartment";

        $coords = $this->extractLatLong($html->find('#gm-canvas-wrap', 0));
        
        // title
        $titleElement = $html->find('.content h1', 0);
        if ($titleElement) {
            // Extract listing_id from <small>
            $listing_id = '';
            $small = $titleElement->find('small', 0);
            if ($small) {
                $listing_id = trim(str_replace('REF. NO.', '', $small->plaintext));
            }

            // Remove <small> and its content
            $titleElement->innertext = preg_replace('/<small.*?<\/small>/is', '', $titleElement->innertext);

            // Remove <br> and <br /> tags
            $titleElement->innertext = preg_replace('/<br\s*\/?>/i', ' ', $titleElement->innertext);

            // Clean and trim extra spaces
            $title = trim(preg_replace('/\s+/', ' ', $titleElement->plaintext));
        }

      
        // Extract the description
        $descriptionElement = $html->find('.content p', 0);

        // Initialize description as an empty string
        $descriptionHtml = '';

        // Check if description exists
        if ($descriptionElement) {
            // Get the HTML content including tags
            $descriptionHtml = $descriptionElement->outertext;
        }
        
        // property_excerpt
        $plainText = trim(strip_tags($descriptionHtml));
        $translatedExcerpt = substr($plainText, 0, 300);
        

        $priceElement = $html->find('.property-details-box.price strong', 0);
        $statusElement = $html->find('.property-details-box.price', 0);
        

        $price = '';
        $currency = '';
        $status = '';

        if ($priceElement) {
            // Extract price text (e.g., €350,000)
            $priceText = trim($priceElement->plaintext);

            // Extract currency symbol
            preg_match('/[^\d,]+/', $priceText, $matches);
            $currencySymbol = isset($matches[0]) ? trim($matches[0]) : '';

            // Map symbol to currency code
            $currency = ($currencySymbol == '€') ? 'EUR' : $currencySymbol;

            // Remove currency symbol from price and clean
            $price = trim(str_replace($currencySymbol, '', $priceText));
            $price = str_replace(',', '', $price);
            $price = round((float)$price);
        }

        if ($statusElement) {
            // Get the text of the whole div, remove the price portion
            $fullText = trim($statusElement->plaintext);
            $status = trim(str_replace($priceText, '', $fullText));
        }

        $allowedStatuses = ['For Sale', 'REDUCED'];

        // Check if status exists and is in allowed statuses (case-insensitive)
        if (empty($status) || !in_array(strtolower($status), array_map('strtolower', $allowedStatuses))) {
            echo "❌ Skipping property with status: $status\n";
            return; // Exit the function without scraping
        } else {
            $status = 'For Sale';
        }

        $detailsElement = $html->find('#details.property-details', 0); 
        // echo $detailsElement;
        $details = $this->extractDetails($detailsElement);
        

        // Initialize an empty array to store image URLs
        $images = [];

        // Find all .rsContent elements inside the container with the class 'royalSlider'
        $slider = $html->find('#royalslider-property-full .rsContent');
        if (empty($slider) || count($slider) === 0) {
            $slider = $html->find('#royalslider-property-classic .rsContent');
        }
        if ($slider) {
            foreach ($slider as $index => $imgElement) {
                // Find the <a> tag and extract the href attribute (URL of the image)
                $imageUrl = $imgElement->find('a.rsImg', 0)->href;

                // If image URL is valid, add it to the images array
                if (!empty($imageUrl)) {
                    $images[] = $imageUrl;
                }

                // Stop after collecting 10 images
                if (count($images) >= 10) {
                    break;
                }
            }
        } else {
            echo "❌ Skipping property with no image \n";
            return; // Exit the function without scraping
        }

        $yearElement = $html->find('.features-highlights', 0);
        $year = '';
        if ($yearElement) {
            // Loop through all elements with the class "features-highlights-box"
            foreach ($yearElement->find('.features-highlights-box') as $element) {
                // Check if the element contains the text "Build Year"
                if (strpos($element->plaintext, 'Build Year') !== false) {
                    // Extract the year after "Build Year"
                    preg_match('/\d{4}/', $element->plaintext, $matches);

                    if (isset($matches[0])) {
                        $year = $matches[0];
                    }
                }
            }
        }

        $video_url = '';
        $videoSpan = $html->find('.rsContent span.lightgallery-video', 0);

        // Check if the span element is found and then extract the 'data-src' value
        if ($videoSpan) {
            $video_url = $videoSpan->getAttribute('data-src');
            echo "The video URL is: {$video_url}\n"; 
        }

        $features = [];
        $featuresBlock = $html->find('div.features', 0);
        if ($featuresBlock) {
            foreach ($featuresBlock->find('.features_item') as $feature) {
                // Extract the text, removing any HTML tags
                $text = trim(strip_tags($feature->plaintext));

                // Add the text to the features array if it's not empty
                if ($text !== '') {
                    $features[] = $text;
                }
            }
        }

        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $this->translateHtmlPreservingTags($descriptionHtml),
            "property_excerpt" => $translatedExcerpt,
            "price" => $price,
            "currency" => $currency,
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $coords['location'],
            "bedrooms" => $details['bedrooms'],
            "bathrooms" => $details['bathrooms'],
            "size" => $details['size'],
            "size_prefix" => $details['size_prefix'],
            "property_type" => [$type],
            "property_status" => [$status],
            "property_address" => $details['address'],
            "property_area" => "",
            "city" => $details['city'],
            "state" => $details['state'],
            "country" => $details['country'],
            "zip_code" => "",
            "latitude" => $coords['latitude'],
            "longitude" => $coords['longitude'],
            "listing_id" => 'BH'.$listing_id,
            "agent_id" => "150",
            "agent_display_option" => "agent_info",
            "mls_id" => "",
            "office_name" => "",
            "video_url" => $video_url,
            "virtual_tour" => "",
            "images" => $images,
            "property_map" => "1",
            "property_year" => $year,
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

    private function extractDetails($el): array {
        $bedrooms = '';
        $bathrooms = '';
        $address = '';
        $city = '';
        $state = '';
        $country = '';
        $size = '';
        $size_prefix = '';

        // Loop through all elements with the class "property-details-box"
        foreach ($el->find('.property-details-box') as $index => $element) {
            // Get the value of the "data-content" attribute
            $dataContent = $element->attr['data-content'] ?? '';

            // If the data-content attribute contains bedrooms and bathrooms info, extract it
            if (strpos($dataContent, 'Bedrooms') !== false) {
                preg_match('/(\d+)\s*Bedrooms/', $dataContent, $bedroom_match);
                if (isset($bedroom_match[1])) {
                    $bedrooms = $bedroom_match[1]; // Extracted number of bedrooms
                }
            }

            if (strpos($dataContent, 'Bathrooms') !== false) {
                preg_match('/(\d+)\s*Bathrooms/', $dataContent, $bathroom_match);
                if (isset($bathroom_match[1])) {
                    $bathrooms = $bathroom_match[1]; // Extracted number of bathrooms
                }
            }

            

            // Find the second .property-details-box element for the address
            if ($index == 1) { // Assuming the second .property-details-box contains the address info
                $strongElement = $element->find('strong', 0); // Get the strong element (which contains the state)
                $state = $strongElement ? $strongElement->plaintext : ''; // The content of <strong> is the state

                $city = trim(str_replace($state, '', $element->plaintext)); // The city is the part outside <strong>
                $country = 'Cyprus';
                $address = $state . ', ' . $city. ', ' . $country; // Combine state and city as the full address
            }


            // Check if the element contains "Covered" and extract the area value from the <strong> tag
            if (strpos($dataContent, 'Covered') !== false || $index == 4) {
                // Extract the content inside the <strong> tag
                $strongElement = $element->find('strong', 0); // Find the <strong> element
                if ($strongElement) {
                    $size = $strongElement->plaintext; // Extract the area value (e.g., 110m²)

                    // Check if the area contains "m²" and replace it with "sqm"
                    if (strpos($size, 'm&sup2;') !== false) {
                        $size = str_replace('m&sup2;', '', $size);
                        $size_prefix = 'sqm';
                    }
                }
            }


            if ($bedrooms && $bathrooms && $address && $city && $state && $country && $size) {
                break;
            }   

        }

        return [
            'bedrooms' => $bedrooms,
            'bathrooms' => $bathrooms,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'size' => $size,
            'size_prefix' => $size_prefix
        ];
    }

    private function extractLatLong($locdata): array {

        if (strpos($locdata, 'data-map-lat') !== false && strpos($locdata, 'data-map-lon') !== false) {

            preg_match('/data-map-lat="([^"]+)"/', $locdata, $latitude_match);
            preg_match('/data-map-lon="([^"]+)"/', $locdata, $longitude_match);

            if (!empty($latitude_match) && !empty($longitude_match)) {
                return [
                    'location' => $latitude_match[1] . ', ' . $longitude_match[1],
                    'latitude' => $latitude_match[1],  
                    'longitude' => $longitude_match[1]
                ];
            }
        }

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

