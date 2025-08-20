<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class MyBali {
    private string $baseUrl = "https://mybali-realestate.com";
    private string $foldername = "MyBali";
    private string $filename = "Villa.json";
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
            $url = $this->baseUrl . "/advanced-search/page/{$page}/?advanced_area%5B0%5D=canggu&advanced_area%5B1%5D=babakan&advanced_area%5B2%5D=berawa&advanced_area%5B3%5D=canggu-canggu&advanced_area%5B4%5D=cemagi&advanced_area%5B5%5D=munggu&advanced_area%5B6%5D=padonan&advanced_area%5B7%5D=pererenan&advanced_area%5B8%5D=seseh&advanced_area%5B9%5D=tumbak-bayuh&advanced_area%5B10%5D=umalas&advanced_area%5B11%5D=tabanan&advanced_area%5B12%5D=kaba-kaba&advanced_area%5B13%5D=kedungu&advanced_area%5B14%5D=nyanyi&advanced_area%5B15%5D=uluwatu-area&advanced_area%5B16%5D=balangan&advanced_area%5B17%5D=bingin&advanced_area%5B18%5D=nunggalan&advanced_area%5B19%5D=nyang-nyang&advanced_area%5B20%5D=padang-padang&advanced_area%5B21%5D=pecatu&advanced_area%5B22%5D=suluban&advanced_area%5B23%5D=ungasan&filter_search_type%5B0%5D=villas&submit=Search&elementor_form_id=18618";
            
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
        // file_put_contents('test.html', $html);
        // return;
        foreach ($html->find('.listing-unit-img-wrapper  a') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/property/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $locationElement = $a->find('.location', 0);
                $locationText = $locationElement ? trim($locationElement->plaintext) : '';
                $this->propertyLinks[] = $fullUrl;
            }
            
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
       
        $ownedBy = "MyBali Real Estate";
        $contactPerson = "Iwan Izzard";
        $phone = "+62 859 2743 5985 / +62 817 4711 825";
        $email = "Thewhitesandvillas@gmail.com";
        $type = "Villa";
        $status = "For Sale";
        
     
        // title
        $title = trim($html->find('h1.entry-title', 0)->plaintext ?? '');

        // Extract the property description
        $descriptionElement = $html->find('.wpestate_property_description .panel-body', 0);

        // Initialize description
        $descriptionHtml = '';

        if ($descriptionElement) {
            // Remove the jp-relatedposts div if it exists
            $relatedPosts = $descriptionElement->find('#jp-relatedposts', 0);
            if ($relatedPosts) {
                $relatedPosts->outertext = '';
            }
            
            $descriptionHtml = $descriptionElement->innertext;
        }

        // Property excerpt - clean up whitespace
        $plainText = strip_tags($descriptionHtml);
        $plainText = preg_replace('/\s+/', ' ', trim($plainText)); // Replace multiple whitespace with single space and trim
        $translatedExcerpt = substr($plainText, 0, 300);

        // Price and Currency
        $priceElement = $html->find('div.price_area', 0);

        $price = '';
        $currency = '';

        if ($priceElement) {
            try {
                // Get the full HTML content
                $priceHtml = $priceElement->innertext;
                
                // Remove any nested divs (like second_price_area)
                $priceHtml = preg_replace('/<div[^>]*>.*?<\/div>/i', '', $priceHtml);
                
                // Convert HTML entities and clean up
                $priceText = html_entity_decode($priceHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $priceText = strip_tags($priceText);
                $priceText = trim($priceText);
                
                // Extract IDR currency and price (with decimal support)
                if (preg_match('/IDR\s*([\d,]+(?:\.[\d]+)?)/', $priceText, $matches)) {
                    $currency = 'IDR';
                    // Remove commas and convert to float, then round to integer
                    $priceValue = str_replace(',', '', $matches[1]);
                    $price = (int)round((float)$priceValue);
                }
                
            } catch (Exception $e) {
                echo "Error extracting price: " . $e->getMessage() . "\n";
                $price = 0;
                $currency = 'IDR';
            }
        }

        // Check if price extraction failed or resulted in zero/invalid price
        if (empty($price) || !is_numeric($price) || (int)$price <= 0) {
            echo "❌ Skipping property with invalid price. Extracted value: '$price'\n";
            return; 
        }


        $overviewElements = $html->find('ul.overview_element');

        $listing_id = '';
        $bedrooms = '';
        $bathrooms = '';
        $size = '';
        $size_prefix = '';

        foreach ($overviewElements as $element) {
            $label = $element->find('li.first_overview_date', 0);
            $value = $element->find('li.first_overview', 0);
            
            if ($label && $value) {
                $labelText = trim($label->plaintext);
                $valueText = trim($value->plaintext);
                
                switch ($labelText) {
                    case 'Category':
                        $type = $valueText;
                        break;
                    case 'Property ID':
                        $listing_id = $valueText;
                        break;
                    case 'Bedrooms':
                        $bedrooms = (int)$valueText;
                        break;
                    case 'Bathrooms':
                        $bathrooms = (float)$valueText; // Changed to float to handle decimals like 1.5
                        break;
                    case 'Property Size':
                        // Extract numeric value and handle m² or sqm
                        if (preg_match('/(\d+)\s*m/', $valueText, $matches)) {
                            $size = (int)$matches[1];
                            $size_prefix = 'sqm';
                        }
                        break;
                }
            }
        }

        $allowedTypes = ['Townhouse', 'Villas', 'Apartment'];
        // Check if property type is allowed (case insensitive comparison)
        if (!in_array(strtolower($type), array_map('strtolower', $allowedTypes))) {
            echo "❌ Skipping property of type: $type\n";
            return; // Exit the function without scraping
        } else {
            $type = 'Villa';
        }

        // Initialize an empty array to store image URLs
        $images = [];

        // Find the image gallery using the owl-carousel structure
        $gallery = $html->find('#owl-demo .item');

        if ($gallery && count($gallery) > 0) {
            foreach ($gallery as $index => $imgElement) {
                // Extract the image URL from the src attribute of the <img> tag
                $imgTag = $imgElement->find('img', 0);
                
                if ($imgTag) {
                    $imageUrl = $imgTag->getAttribute('src');
                    
                    // Remove WordPress parameters (?fit=1840%2C1419&#038;ssl=1)
                    $imageUrl = preg_replace('/\?fit=.*/', '', $imageUrl);
                    
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
            return; // Exit the function without scraping
        }

        // Extract location data from property_categs
        $locationElement = $html->find('.property_categs', 0);

        $city = '';
        $area = '';
        $country = 'Indonesia'; // Fixed value
        $areaToSearch = '';
        if ($locationElement) {
            // Find all links within the location element
            $links = $locationElement->find('a');
            
            $areas = [];
            
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                $text = trim($link->plaintext);
                
                // Check if it's a city link
                if (strpos($href, '/property_city/') !== false) {
                    $city = $text;
                }
                // Check if it's an area link
                elseif (strpos($href, '/property_area/') !== false) {

                    $areas[] = $text;
                    $areaToSearch = $text;
                }
            }
            
            // Combine multiple areas with comma
            if (!empty($areas)) {
                $area = implode(', ', $areas);
            }
        }

        // Create address by combining all location parts
        $addressParts = array_filter([$area, $city, $country]); // Remove empty values
        $address = implode(', ', $addressParts);

        $coords = '';
        $latitude = '';
        $longitude = '';

        if ($address) {
            // Get coordinates using PositionStack API
            $locSearch = $areaToSearch.', '.$city.', '.$country;
            $coordsData = $this->helpers->getCoordinatesFromQuery($locSearch);

            if ($coordsData) {
                $coords = $coordsData['location'];           // String: "lat, lng"
                $latitude = $coordsData['latitude'];         // Float: latitude
                $longitude = $coordsData['longitude'];       // Float: longitude
            }
        }

        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $this->helpers->translateHtmlPreservingTags($descriptionHtml),
            "property_excerpt" => $translatedExcerpt,
            "price" => $price,
            "currency" => $currency,
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $coords,
            "bedrooms" => $bedrooms,
            "bathrooms" => $bathrooms,
            "size" => $size,
            "size_prefix" => $size_prefix,
            "property_type" => [$type],
            "property_status" => [$status],
            "property_address" => $address,
            "property_area" => $area,
            "city" => $city,
            "state" => '',
            "country" => $country,
            "zip_code" => "",
            "latitude" => $latitude,
            "longitude" => $longitude,
            "listing_id" => 'MyBali-'.$listing_id,
            "agent_id" => "150",
            "agent_display_option" => "agent_info",
            "mls_id" => "",
            "office_name" => "",
            "video_url" => "",
            "virtual_tour" => "",
            "images" => $images,
            "property_map" => "1",
            "property_year" => "",
            "additional_features" => "",
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

