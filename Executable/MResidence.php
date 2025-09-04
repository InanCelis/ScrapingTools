<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';

class MResidence {
    private string $baseUrl = "https://mresidence.com/";
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
        $folder = __DIR__ . '/../ScrapeFile/MResidence';
        $outputFile = $folder . '/RestoreVersion.json';
        // $htmlTest =  $folder . '/Test.html';
        // Create the folder if it doesn't exist
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Start a fresh JSON array
        file_put_contents($outputFile, "[");

        $propertyCounter = 0;
        for ($page = 1; $page <= $pageCount; $page++) {
            $url = $this->baseUrl . "properties/buy?types=flat&min-price=50000&max-price=6000000&page={$page}";
            
            echo "📄 Fetching page $page: $url\n";

            $html = $this->getHtmlWithJS($url);
            if (!$html) {
                echo "⚠️ Failed to load page $page. Skipping...\n";
                continue;
            }
            $this->extractPropertyLinks($html);
        }

        // Deduplicate array
        $this->propertyLinks = array_unique($this->propertyLinks);
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
                    // if ($result['success']) {
                    //     echo "✅ Success after {$result['attempts']} attempt(s)\n";
                    //     if (count($result['response']['updated_properties']) > 0) {
                    //         echo "✅ Updated # " . $this->successUpdated++ . "\n";
                    //     } else {
                    //         echo "✅ Created # " . $this->successCreated++ . "\n";
                    //     }
                    // } else {
                    //     echo "❌ Failed after {$result['attempts']} attempts. Last error: {$result['error']}\n";
                    //     if ($result['http_code']) {
                    //         echo "⚠️ HTTP Status: {$result['http_code']}\n";
                    //     }
                    // }
                    // sleep(1);
                }
            }
        }
        // Close the JSON array
        file_put_contents($outputFile, "\n]", FILE_APPEND);

        echo "✅ Scraping completed. Output saved to {$outputFile}\n";
        echo "✅ Properties Created: {$this->successCreated}\n";
        echo "✅ Properties Updated: {$this->successUpdated}\n";
    }

    /**
     * Get HTML using Puppeteer for JavaScript rendering
     * Updated path to use Helpers/js folder
     */
    private function getHtmlWithJS(string $url): ?simple_html_dom {
        $tempFile = tempnam(sys_get_temp_dir(), 'scraped_html_');
        
        // Updated path to your puppeteer script in Helpers/js folder
        $puppeteerScript = __DIR__ . '/../Helpers/js/puppeteer-scraper.js';
        
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

    /**
     * Fallback method using cURL (for non-JS content or debugging)
     */
    private function getHtml(string $url): ?simple_html_dom {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            echo "❌ HTTP Error: $httpCode for URL: $url\n";
            return null;
        }
        
        return $html ? str_get_html($html) : null;
    }

    
    private function extractPropertyLinks(simple_html_dom $html): void {
        // Find all property items using the correct selector
        $propertyItems = $html->find('app-property-item a[href*="/properties/"]');
        
        foreach ($propertyItems as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/properties/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . ltrim($href, '/');
                $this->propertyLinks[] = $fullUrl;
            }
        }
        
        $this->propertyLinks = array_unique($this->propertyLinks);
        echo "🔗 Found " . count($this->propertyLinks) . " property links\n";
    }

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
        $ownedBy = "M. Residence";
        $contactPerson = "Nikolas Michalias";
        $phone = "+357 968 00440";
        $email = "nikolas@mresidence.com";
        $type = "Apartment";

        // $coords = $this->extractLatLong($html->find('#gm-canvas-wrap', 0));
        
        // title
        $title = trim($html->find('h1', 0)->plaintext ?? '');
        
        //Description
        // Extract the property description
        $descriptionElement = $html->find('p.w-full.text-gray-850', 0);

        // Initialize description as an empty string
        $descriptionHtml = '';
        // Check if description exists
        if ($descriptionElement) {
            // Get the HTML content including tags
            $descriptionHtml = $descriptionElement->outertext;
            // Remove the class attribute from the <p> tag
            $descriptionHtml = preg_replace('/<p[^>]*class="[^"]*"([^>]*)>/', '<p$1>', $descriptionHtml);
        }

        // property_excerpt
        $plainText = trim(strip_tags($descriptionHtml));
        $translatedExcerpt = substr($plainText, 0, 300);
        

        // Extract the price element
        $priceElement = $html->find('div.price p.text-2xl', 0);

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
                        '¥' => 'JPY',
                        '₹' => 'INR'
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
            return; 
        }
        // In your scrapePropertyDetails method, update the selector:
        $detailsContainer = $html->find('app-property-features', 0) ?: 
                            $html->find('#property-features', 0) ?:
                            $html->find('div.grid.grid-cols-2', 0) ?:
                            $html;

        $details = $this->extractDetails($detailsContainer);



        $allowedStatuses = ['New', 'Resale', 'Under Construction', 'Off-Plan'];
        $status = $details['status'];
        if (empty($status) || !in_array(strtolower($status), array_map('strtolower', $allowedStatuses))) {
            echo "❌ Skipping property with status: $status\n";
            return; 
        } else {
            $details['status'] = 'For Sale';
        }
        // Extract listing ID
        $listing_id = '';

        // Method 1: Look for specific paragraph with ref
        $refElements = $html->find('p');
        foreach ($refElements as $p) {
            $text = $p->plaintext ?? '';
            if (strpos($text, 'Property Ref:') !== false) {
                // Use regex to extract just the numbers
                if (preg_match('/Property Ref:\s*#?(\d+)/', $text, $matches)) {
                    $listing_id = $matches[1];
                    break;
                }
                // Fallback: manual string replacement
                if (!$listing_id) {
                    $listing_id = trim(str_replace(['Property Ref:', '#', ' '], '', $text));
                    // Keep only digits
                    $listing_id = preg_replace('/[^\d]/', '', $listing_id);
                }
                if ($listing_id) break;
            }
        }


        $images = [];
        $jsonScript = $html->find('script[type="application/ld+json"]', 0);

        if ($jsonScript) {
            $jsonText = $jsonScript->innertext;
            // Decode the JSON
            $jsonData = json_decode($jsonText, true);

            if($jsonData && isset($jsonData['name'])) {
                $title = $jsonData['name'];
            }
            
            if ($jsonData && isset($jsonData['photo'])) {
                $photos = $jsonData['photo'];
                // Process images - limit to 10
                foreach ($photos as $photo) {
                    if (!empty($photo) && filter_var($photo, FILTER_VALIDATE_URL)) {
                        // Add image URL directly (they're already full URLs)
                        $images[] = $photo;
                        
                        // Stop when we have 10 images
                        if (count($images) >= 10) {
                            break;
                        }
                    }
                }
            } 
        } 

        // Fallback: Look for images in other places if JSON-LD doesn't work
        if (empty($images)) {
            
            // Try to find images in swiper or gallery elements
            $swiperImages = $html->find('div.swiper-slide');
            foreach ($swiperImages as $slide) {
                $style = $slide->style ?? '';
                // Extract background-image URLs
                if (preg_match('/background-image:\s*url\([\'"]?([^\'")]+)[\'"]?\)/', $style, $matches)) {
                    $imageUrl = $matches[1];
                    if (!in_array($imageUrl, $images) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                        $images[] = $imageUrl;
                        if (count($images) >= 10) break;
                    }
                }
            }
        }
        // Ensure we have exactly 10 images (or fewer if that's all we found)
        $images = array_slice($images, 0, 10);

        if (count($images) <= 0) {
            echo "❌ Skipping property with no image \n";
            return; 
        }


        // Extract location from breadcrumb
        $address = '';
        $city = '';
        $area = '';
        $country = 'Cyprus'; // Default country for M.Residence

        $breadcrumbContainer = $html->find('div.area-breadcrumb', 0);

        if ($breadcrumbContainer) {
            // Get all the breadcrumb links
            $breadcrumbLinks = $breadcrumbContainer->find('a');
            $locations = [];
            
            foreach ($breadcrumbLinks as $link) {
                $locationText = trim($link->plaintext);
                if (!empty($locationText)) {
                    $locations[] = $locationText;
                }
            }
            
            // Parse based on number of locations found
            if (count($locations) >= 2) {
                // Last item is always the area
                $area = end($locations);
                
                // Second to last is always the city
                $city = $locations[count($locations) - 2];
                
                // If there are 3 items, first might be province/district (but in Cyprus it's usually redundant)
                if (count($locations) >= 3) {
                    // For Cyprus, usually: District > City > Area
                    // But since District and City are often the same, we keep our logic
                    echo "⚠️ Found 3+ breadcrumb items, using last 2\n";
                }
            } elseif (count($locations) == 1) {
                // Only one location found, assume it's the city
                $city = $locations[0];
            }
            
        } 

        // Create full address
        $addressParts = array_filter([$area, $city, $country]);
        $address = implode(', ', $addressParts);


        $jsonScript = $html->find('script#serverApp-state[type="application/json"]', 0);
        $coords = $this->extractLatLong($jsonScript);

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
            "property_status" => [$details['status']],
            "property_address" => $address,
            "property_area" => $area,
            "city" => $city,
            "state" => '',
            "country" => $country,
            "zip_code" => "",
            "latitude" => $coords['latitude'],
            "longitude" => $coords['longitude'],
            "listing_id" => 'MRSDNC_' . $listing_id,
            "agent_id" => "150",
            "agent_display_option" => "agent_info",
            "mls_id" => "",
            "office_name" => "",
            "video_url" => "",
            "virtual_tour" => "",
            "images" => $images,
            "property_map" => "1",
            "property_year" => $details['built_year'],
            "additional_features" => $details['features'],
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
        $bedrooms = '';
        $bathrooms = '';
        $address = '';
        $city = '';
        $state = '';
        $country = '';
        $size = '';
        $size_prefix = '';
        $type = '';
        $status = '';
        $built_year = '';
        $features = [];

        // Safety check for null
        if (!$html) {
            echo "HTML element is null in extractDetails\n";
            return [
                'bedrooms' => '0',
                'bathrooms' => '0',
                'size' => '0',
                'size_prefix' => 'sqm',
                'type' => '',
                'status' => '',
                'built_year' => '',
                'features' => []
            ];
        }

        // Find the property features container
        $featuresContainer = $html->find('app-property-features', 0) ?: 
                            $html->find('#property-features', 0) ?:
                            $html->find('div.grid.grid-cols-2', 0);
        
        if ($featuresContainer) {
            // Find all feature divs
            $featureDivs = $featuresContainer->find('div.flex.items-center.py-2.space-x-4');
            
            foreach ($featureDivs as $div) {
                $divText = $div->plaintext ?? '';
                
                // Extract Type
                if (strpos($divText, 'Type') !== false) {
                    $typeElement = $div->find('p.font-semibold.text-primary', 0);
                    if ($typeElement) {
                        $type = trim($typeElement->plaintext);
                    }
                }
                
                // Extract Status
                if (strpos($divText, 'Status') !== false) {
                    $statusElement = $div->find('p.font-semibold.text-primary', 0);
                    if ($statusElement) {
                        $status = trim($statusElement->plaintext);
                    }
                }
                
                // Extract Bedrooms
                if (strpos($divText, 'Beds') !== false) {
                    $bedroomsElement = $div->find('p.font-semibold.text-primary', 0);
                    if ($bedroomsElement) {
                        $bedrooms = trim($bedroomsElement->plaintext);
                    }
                }
                
                // Extract Bathrooms
                if (strpos($divText, 'Baths') !== false) {
                    $bathroomsElement = $div->find('p.font-semibold.text-primary', 0);
                    if ($bathroomsElement) {
                        $bathrooms = trim($bathroomsElement->plaintext);
                    }
                }
                
                // Extract Area
                if (strpos($divText, 'Area') !== false) {
                    $areaElement = $div->find('p.font-semibold.text-primary', 0);
                    if ($areaElement) {
                        $areaText = trim($areaElement->plaintext);
                        
                        // Extract numeric value and handle m² or sqm
                        if (preg_match('/(\d+)\s*m/', $areaText, $matches)) {
                            $size = $matches[1];
                            $size_prefix = 'sqm';
                        }
                    }
                }
                
                // Extract Built Year (B.Y)
                if (strpos($divText, 'B.Y') !== false) {
                    $yearElement = $div->find('p.font-semibold.text-primary', 0);
                    if ($yearElement) {
                        $built_year = trim($yearElement->plaintext);
                    }
                }
            }
            
            // Extract additional features from "Even More Info" section
            $moreInfoSection = $featuresContainer->find('div.col-span-full', -1); // Get the last col-span-full div
            if ($moreInfoSection) {
                $featureItems = $moreInfoSection->find('div.w\\:1\\/3');
                if (!$featureItems) {
                    $featureItems = $moreInfoSection->find('div.text-base.flex.items-center');
                }
                
                foreach ($featureItems as $item) {
                    $featureText = $item->find('p', 0);
                    if ($featureText) {
                        $feature = trim($featureText->plaintext);
                        if (!empty($feature)) {
                            $features[] = $feature;
                        }
                    }
                }
            }
        }

        // Fallback method using regex patterns if the above doesn't work
        if (!$bedrooms || !$bathrooms || !$size || !$type || !$status) {
            echo "Using fallback extraction method...\n";
            
            $htmlText = $html->outertext ?? '';
            
            // Extract Type
            if (!$type && preg_match('/<p[^>]*class="[^"]*font-semibold[^"]*text-primary[^"]*"[^>]*>(Detached House|Apartment|Villa|Townhouse|[^<]+)<\/p>/i', $htmlText, $matches)) {
                $type = trim($matches[1]);
            }
            
            // Extract Status
            if (!$status && preg_match('/<p[^>]*class="[^"]*font-semibold[^"]*text-primary[^"]*"[^>]*>(Resale|New|Under Construction|[^<]+)<\/p>/i', $htmlText, $matches)) {
                $status = trim($matches[1]);
            }
            
            // Extract numeric values for beds, baths, area
            if (!$bedrooms && preg_match('/(\d+)\s*Beds?/', $htmlText, $matches)) {
                $bedrooms = $matches[1];
            }
            
            if (!$bathrooms && preg_match('/(\d+)\s*Baths?/', $htmlText, $matches)) {
                $bathrooms = $matches[1];
            }
            
            if (!$size && preg_match('/(\d+)\s*m(?:<sup>2<\/sup>|²)/', $htmlText, $matches)) {
                $size = $matches[1];
                $size_prefix = 'sqm';
            }
            
            if (!$built_year && preg_match('/B\.Y[^>]*>(\d{4})</', $htmlText, $matches)) {
                $built_year = $matches[1];
            }
        }

        // Set defaults if still empty
        $bedrooms = $bedrooms ?: '0';
        $bathrooms = $bathrooms ?: '0';
        $size = $size ?: '0';
        $size_prefix = $size_prefix ?: 'sqm';
        $type = $type ?: '';
        $status = $status ?: '';
        $built_year = $built_year ?: '';

        // echo "Final extracted values - Type: $type, Status: $status, Bedrooms: $bedrooms, Bathrooms: $bathrooms, Size: $size $size_prefix, Built Year: $built_year\n";
        // echo "Features: " . implode(', ', $features) . "\n";

        return [
            'bedrooms' => $bedrooms,
            'bathrooms' => $bathrooms,
            'size' => $size,
            'size_prefix' => $size_prefix,
            'type' => $type,
            'status' => $status,
            'built_year' => $built_year,
            'features' => $features
        ];
    }


    // Alternative: Extract exact coordinates
    function extractLatLong($html) {
        $coords = ['location' => '', 'latitude' => '', 'longitude' => ''];
        
        $jsonScript = $html;
        
        if ($jsonScript) {
            $jsonText = $jsonScript->innertext;
            $decodedJson = html_entity_decode($jsonText);
            $decodedJson = str_replace(['&q;', '&l;', '&g;', '&a;'], ['"', '<', '>', '&'], $decodedJson);
            
            $jsonData = json_decode($decodedJson, true);
            
            if ($jsonData) {
                foreach (array_keys($jsonData) as $key) {
                    if (strpos($key, 'api/v1/properties/') !== false && strpos($key, 'includes=location') !== false) {
                        $location = $jsonData[$key]['body']['data']['location'];
                        
                        // Use exact coordinates if available
                        if (isset($location['exactLatitude']) && isset($location['exactLongitude'])) {
                            $coords = [
                                'location' => $location['exactLatitude'] . ', ' . $location['exactLongitude'],
                                'latitude' => (string)$location['exactLatitude'],
                                'longitude' => (string)$location['exactLongitude']
                            ];
                        }
                        break;
                    }
                }
            }
        }
        
        return $coords;
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