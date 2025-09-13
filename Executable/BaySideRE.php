<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class BaySideRE {
    private string $baseUrl = "https://baysiderealestate.com";
    private string $foldername = "BaySideRE";
    private string $filename = "Properties.json";
    private array $propertyLinks = [];
    private array $scrapedData = [];
    private ApiSender $apiSender;
    private ScraperHelpers $helpers;
    private int $successCreated;
    private int $successUpdated;
    private bool $enableUpload = false;
    private bool $testingMode = false;

    public function __construct() {
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
            $url = $this->baseUrl . "/advanced-search/page/{$page}/";
            
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


    private function extractPropertyLinks(simple_html_dom $html): void {
        if($this->testingMode) {
            // file_put_contents('test.html', $html);
            // return;
        }
        
        foreach ($html->find('#listing_ajax_container .listing_wrapper h4 a') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/properties/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $this->propertyLinks[] = $fullUrl;
            }
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
       
        $ownedBy = "Bayside Real Estate";
        $contactPerson = "Brent May";
        $phone = "+52 1 958 109 9771";
        $email = "info@baysidehuatulco.com";
        

        $title = trim($html->find('.wpestate_estate_property_design_intext_details h1.property-title', 0)->plaintext ?? '');
        if(empty($title)) {
            echo "❌ Skipping property with invalid setup of html\n ";
            return; 
        }
        
        $descriptionHtml = '';
        $plainText = '';
        $translatedExcerpt = '';
        $listing_id = '';
        $price = '';
        $currency = '';
        $size = '';
        $size_prefix = '';
        $bedrooms = '';
        $bathrooms = '';
        $property_type = [];
        $property_status = [];
        $city = '';
        $area = '';
        $state = '';
        $country = '';
        

        // Look for any collapse element with "collapseDesc" in the ID
        $collapseElements = $html->find('[id*="collapseDesc"]');
        if (!empty($collapseElements)) {
            $panelBody = $collapseElements[0]->find('.panel-body', 0);
            if ($panelBody) {
                $descriptionHtml = $panelBody->innertext;
                
                // Clean HTML - remove classes, styles, and unnecessary attributes
                $descriptionHtml = preg_replace('/class="[^"]*"/', '', $descriptionHtml);
                $descriptionHtml = preg_replace('/style="[^"]*"/', '', $descriptionHtml);
                $descriptionHtml = preg_replace('/<span[^>]*>/', '', $descriptionHtml);
                $descriptionHtml = str_replace('</span>', '', $descriptionHtml);
                $descriptionHtml = str_replace('<b></b>', '', $descriptionHtml);
                
                // Clean up extra spaces
                $descriptionHtml = preg_replace('/\s+/', ' ', $descriptionHtml);
                $descriptionHtml = trim($descriptionHtml);
                
                $plainText = strip_tags($descriptionHtml);
                $translatedExcerpt = strlen($plainText) > 300 ? substr($plainText, 0, 297) . '...' : $plainText;
            }
        }

        // Extract property details from Details panel
        $detailsElements = $html->find('[id*="collapseOne"]');
        if (!empty($detailsElements)) {
            $detailsBody = $detailsElements[0]->find('.panel-body', 0);
            if ($detailsBody) {
                $detailsHtml = $detailsBody->innertext;
                
                // Extract Property ID
                if (preg_match('/<strong>Property Id :<\/strong>\s*([^<]+)/', $detailsHtml, $matches)) {
                    $listing_id = trim($matches[1]);
                }
                
                // Extract Price and Currency - improved method
                $priceElement = null;
                if (preg_match('/<strong>Price:<\/strong>\s*([^<]+)/', $detailsHtml, $priceMatches)) {
                    $priceElement = (object)['innertext' => $priceMatches[1]];
                }
                
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
                
                // Extract Size - improved method
                $size = '';
                $size_prefix = '';
                
                // First try the property details panel
                if (preg_match('/<strong>Property Size:<\/strong>\s*([\d,]+)\s*ft/', $detailsHtml, $matches)) {
                    $size = str_replace(',', '', trim($matches[1]));
                    $size_prefix = 'sqft';
                }
                
                // If not found in details, try the grid_hl method
                if (empty($size)) {
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
                }
                
                // Extract Bedrooms
                if (preg_match('/<strong>Bedrooms:<\/strong>\s*(\d+)/', $detailsHtml, $matches)) {
                    $bedrooms = trim($matches[1]);
                }
                
                // Extract Bathrooms
                if (preg_match('/<strong>Bathrooms:<\/strong>\s*(\d+)/', $detailsHtml, $matches)) {
                    $bathrooms = trim($matches[1]);
                }
            }
        }

        // Extract property type and status from the design details section
        $designDetailLinks = $html->find('.wpestate_estate_property_design_intext_details span a[rel="tag"]');
        
        if (!empty($designDetailLinks)) {
            // Define allowed values
            $status_allowed = ['For Sale', 'For Rent'];
            $allowed_type = ['Villa', 'Condo', 'Apartment', 'House', 'Penthouse', 'Casa', 'Studio','Studios', 'Home'];
            
            // Extract from each link
            foreach ($designDetailLinks as $link) {
                $linkText = trim($link->plaintext);
                
                // Check for status
                foreach ($status_allowed as $status) {
                    if (stripos($linkText, $status) !== false) {
                        $property_status[] = $status;
                        break;
                    }
                }
                // Check for type
                foreach ($allowed_type as $type) {
                    if (stripos($linkText, $type) !== false) {

                        if($type == 'Casa' || $type == 'Home') {
                            $type = 'House';
                        }
                        $property_type[] = $type;
                        break;
                    }
                }
            }
        } else {
            echo "❌ No tag links found\n";
            return;
        }
        
        if (empty($property_status)) {
            echo "❌ Skipping property with invalid status\n";
            return; // Exit the function without scraping
        }

        if (empty($property_type)) {
            echo "❌ Skipping property with invalid property type\n";
            return; // Exit the function without scraping
        }
        

        // Extract address information from Address panel
        $addressElements = $html->find('[id*="collapseTwo"]');
        if (!empty($addressElements)) {
            $addressBody = $addressElements[0]->find('.panel-body', 0);
            if ($addressBody) {
                $addressHtml = $addressBody->innertext;
                
                // Extract City
                if (preg_match('/<strong>City:<\/strong>\s*<a[^>]*>([^<]+)<\/a>/', $addressHtml, $matches)) {
                    $city = trim($matches[1]);
                }
                
                // Extract Area
                if (preg_match('/<strong>Area:<\/strong>\s*<a[^>]*>([^<]+)<\/a>/', $addressHtml, $matches)) {
                    $area = trim($matches[1]);
                }
                
                // Extract State/County
                if (preg_match('/<strong>State\/County:<\/strong>\s*<a[^>]*>([^<]+)<\/a>/', $addressHtml, $matches)) {
                    $state = trim($matches[1]);
                }
                
                // Extract Country (no link, just text)
                if (preg_match('/<strong>Country:<\/strong>\s*([^<]+)/', $addressHtml, $matches)) {
                    $country = trim($matches[1]);
                }
            }
        }

        // Create full address by imploding non-empty address parts
        $addressParts = array_filter([$area, $city, $state, $country]);
        $property_address = implode(', ', $addressParts);



        $images = [];
         // Extract images from carousel
        $carouselContainer = $html->find('#carousel-listing', 0);
        
        if ($carouselContainer) {
            // Find all anchor tags with prettygalery class within carousel
            $imageLinks = $carouselContainer->find('a.prettygalery');
            
            if ($imageLinks && count($imageLinks) > 0) {
                foreach ($imageLinks as $index => $linkElement) {
                    // Get the href attribute which contains the full-size scaled image URL
                    $imageUrl = $linkElement->getAttribute('href');
                    
                    if ($imageUrl) {
                        // First priority: scaled images
                        if (strpos($imageUrl, '-scaled.jpg') !== false || strpos($imageUrl, '-scaled.png') !== false) {
                            $images[] = $imageUrl;
                        }
                        // Second priority: any other image if no scaled found yet
                        else {
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



        $features = [];
        // Extract features from Amenities and Features panel
        $featuresElements = $html->find('[id*="collapseThree"]');
        if (!empty($featuresElements)) {
            $featuresBody = $featuresElements[0]->find('.panel-body', 0);
            if ($featuresBody) {
                // Find all feature items with check icons
                $featureItems = $featuresBody->find('div.listing_detail i.fas.fa-check');
                
                foreach ($featureItems as $item) {
                    // Get the parent div and extract the text after the icon
                    $parentDiv = $item->parent();
                    $featureText = trim(strip_tags($parentDiv->plaintext));
                    
                    if (!empty($featureText)) {
                        $features[] = $featureText;
                    }
                }
            }
        }

        $latitude = '';
        $longitude = '';
        $location = '';
        // Extract latitude and longitude from Google Map element
        $mapElement = $html->find('.googleMap_shortcode_class', 0);
        if ($mapElement) {
            $latitude = $mapElement->getAttribute('data-cur_lat');
            $longitude = $mapElement->getAttribute('data-cur_long');
            
            if ($latitude && $longitude) {
                $location = $latitude . ', ' . $longitude;
            }
        }

        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $descriptionHtml,
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
            "property_status" => $property_status,
            "property_address" => $property_address,
            "property_area" => $area,
            "city" => $city,
            "state" => $state,
            "country" => $country,
            "zip_code" => '',
            "latitude" => $latitude,
            "longitude" => $longitude,
            "listing_id" => 'BSRE-'.$listing_id,
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

