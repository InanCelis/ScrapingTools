<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class AlSabr {
    private string $baseUrl = "https://al-sabr.com";
    private string $foldername = "AlSabr";
    private string $filename = "Properties.json";
    private string $localHtmlFile = ""; // Path to local HTML file
    private array $propertyLinks = [];
    private array $scrapedData = [];
    private ApiSender $apiSender;
    private ScraperHelpers $helpers;
    private int $successCreated;
    private int $successUpdated;
    private bool $enableUpload = true;
    private bool $testingMode = false;
    private bool $useLocalFile = false; // Flag to determine scraping mode

    public function __construct(string $localHtmlFile = '') {
        // Initialize the ApiSender with your actual API URL and token
        $this->apiSender = new ApiSender(true);
        $this->helpers = new ScraperHelpers();
        $this->successCreated = 0;
        $this->successUpdated = 0;
        
        // If local HTML file is provided, set it up
        if (!empty($localHtmlFile)) {
            $this->localHtmlFile = $localHtmlFile;
            $this->useLocalFile = true;
        }
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
        
        if ($this->useLocalFile) {
            // Scrape from local HTML file
            $this->scrapeFromLocalFile();
        } else {
            // Original website scraping logic
            for ($page = 9; $page <= $pageCount; $page++) {
                $url = $this->baseUrl . "/properties-sale";
                
                echo "📄 Fetching page $page: $url\n";

                $html = $this->helpers->getHtmlWithJS($url);
                if (!$html) {
                    echo "⚠️ Failed to load page $page. Skipping...\n";
                    continue;
                }
                $this->extractPropertyLinks($html);
            }
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

    /**
     * Scrape property links from local HTML file
     */
    private function scrapeFromLocalFile(): void {
        if (!file_exists($this->localHtmlFile)) {
            echo "❌ Local HTML file not found: {$this->localHtmlFile}\n";
            return;
        }

        echo "📄 Reading local HTML file: {$this->localHtmlFile}\n";
        
        // Read the local HTML file
        $htmlContent = file_get_contents($this->localHtmlFile);
        if (!$htmlContent) {
            echo "❌ Failed to read local HTML file\n";
            return;
        }

        // Parse the HTML content
        $html = str_get_html($htmlContent);
        if (!$html) {
            echo "❌ Failed to parse HTML content\n";
            return;
        }

        // Extract property links from the local HTML
        $this->extractPropertyLinks($html);
        
        echo "✅ Found " . count($this->propertyLinks) . " property links in local file\n";
        
        // Clean up
        $html->clear();
    }

    /**
     * Set the local HTML file path
     */
    public function setLocalHtmlFile(string $filePath): void {
        $this->localHtmlFile = $filePath;
        $this->useLocalFile = true;
    }

    /**
     * Enable or disable local file mode
     */
    public function setUseLocalFile(bool $useLocal): void {
        $this->useLocalFile = $useLocal;
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

        foreach ($html->find('.framer-mefxqe .framer-4sc1en .framer-1j2oag6-container a.framer-mUrOQ') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/properties-sale') !== false) {
                // Clean the href by removing any dots that shouldn't be there
                $cleanHref = $this->cleanHref($href);
                $fullUrl = strpos($cleanHref, 'http') === 0 ? $cleanHref : $this->baseUrl . $cleanHref;
                $this->propertyLinks[] = $fullUrl;
            }
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function cleanHref(string $href): string {
        // Nuclear option: completely rebuild the URL if it contains al-sabr.com.
        if (strpos($href, './') !== false) {
            // Extract everything after the problematic domain
            $parts = explode('./', $href);
            if (count($parts) >= 2) {
                $cleanedHref = '/' . $parts[1];
                return $cleanedHref;
            }
        }
        
        // Fallback to original cleaning methods
        return str_replace('./', '/', $href);
    }

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
        // Your existing scrapePropertyDetails method remains unchanged
        $ownedBy = "Al Sabr Properties";
        $contactPerson = "Salik Bin Rashid";
        $phone = "+97 1554 056094";
        $email = "salik.alsabr@gmail.com";

        $title = trim($html->find('h1.framer-text.framer-styles-preset-2otcow', 0)->plaintext ?? '');
        if(empty($title)) {
            echo "❌ Skipping property with invalid setup of html\n ";
            return; 
        }

        // Extract the description
        $descriptionElement = $html->find('.framer-17wvew6 .framer-ksc5an .framer-exjokr', 0);

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

        if(empty($translatedExcerpt)) {
            $descriptionHtml = '';
        }

        $price = '';
        $currency = '';
        $status = [];


        $priceElement = $html->find('.framer-1n5lic0 .framer-1mktrrx p.framer-text', 0);
        if ($priceElement) {
            try {
                // Get the price text and ensure UTF-8 encoding
                $priceText = trim($priceElement->plaintext);
                // Check for AED currency first (as it appears as text, not symbol)
                if (mb_strpos($priceText, 'AED', 0, 'UTF-8') !== false) {
                    $currency = 'AED';
                } 
                // Extract numeric value (remove everything except numbers and commas)
                $numericPrice = preg_replace('/[^0-9,]/', '', $priceText);
                $price = (int)str_replace(',', '', $numericPrice);
                
            } catch (Exception $e) {
                echo "Error extracting price: " . $e->getMessage() . "\n";
                $price = 0;
                $currency = 'USD';
            }
        }

        // Extract status from the tag element
        $statusElement = $html->find('.framer-1n5lic0 .framer-11ugzlb .framer-vqtzt3 p.framer-text', 0); // Get the last element with framer-text class
        if ($statusElement) {
            $trim = trim(strtolower($statusElement->plaintext));
            // Normalize status values
            switch ($trim) {
                case 'for sale':
                    $status[] = 'For Sale';
                    break;
                default:
                    $status[] = 'For Sale'; // Default status
            }
        }


        $beds = 0;
        $baths = 0;
        $size = '';
        $size_prefix = '';

        // Find all "Info Wrapper" blocks (quoted because of the space)
        $wrappers = $html->find('div[data-framer-name="Info Wrapper"]');

        foreach ($wrappers as $wrapper) {
            // Grab the first two <p> tags inside (value then label)
            $valueEl = $wrapper->find('p.framer-text', 0);
            $labelEl = $wrapper->find('p.framer-text', 1);

            if (!$valueEl || !$labelEl) continue;

            $rawValue = trim($valueEl->plaintext);
            $label = strtolower(trim($labelEl->plaintext));

            // Remove commas, keep only numbers and optional decimal
            $clean = preg_replace('/[^\d.]/', '', $rawValue);

            // Force integer (drops decimals like .50)
            $num = (int) $clean;

            if ($label === 'beds') {
                $beds = $num;
            } elseif ($label === 'baths' || $label === 'bath') {
                $baths = $num;
            } elseif ($label === 'sqft' || $label === 'sqm') {
                $size = $num; // always integer
                $size_prefix = $label; // 'sqft' or 'sqm'
            }
        }

        $property_type = [];

        // Get all elements with data-framer-name="Property Type"
        $typeEls = $html->find('div[data-framer-name="Property Type"]');

        if (count($typeEls) > 1) {
            // The second one contains the actual type (e.g. Villa)
            $type_extracted = trim($typeEls[1]->plaintext);
            

            $allowedTypes = ['Townhouse', 'Villa', 'Apartment', 'House', 'Hotel'];
             // Check if property type is allowed (case insensitive comparison)
            if (!in_array(strtolower($type_extracted ), array_map('strtolower', $allowedTypes))) {
                echo "❌ Skipping property of type: $type_extracted\n";
                return; // Exit the function without scraping
            }

            $property_type[] = $type_extracted;
        }

        $lat = '';
        $lng = '';
        $location = '';
        $address_data = [];

        $iframe = $html->find('#Z26mEXQfe iframe', 0);

        if ($iframe) {
            $src = $iframe->src ?: $iframe->getAttribute('src');
            
            if (!$src) {
                // Extract from HTML
                if (preg_match('/src="([^"]*)"/', $iframe->outertext, $matches)) {
                    $src = $matches[1];
                }
            }
            
            if ($src) {
                $decodedSrc = urldecode(html_entity_decode($src));
                // Try multiple regex patterns
                $patterns = [
                    '/q=([\d.-]+),([\d.-]+)/',           // q=lat,lng
                    '/q=([\d.-]+)%2C([\d.-]+)/',        // q=lat%2Clng
                    '/q=([\d.-]+)\s*,\s*([\d.-]+)/',    // q=lat , lng (with spaces)
                    '/q=([\d.+-]+)[,\s%2C]+([\d.+-]+)/', // More flexible
                ];
                
                $found = false;
                foreach ($patterns as $i => $pattern) {
                    if (preg_match($pattern, $decodedSrc, $matches)) {
                        $lat = trim($matches[1]);
                        $lng = trim($matches[2]);
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    
                    // Find the q= part
                    if (preg_match('/q=([^&]+)/', $decodedSrc, $matches)) {
                        $coordString = $matches[1];
                        
                        // Split by comma or any separator
                        $coords = preg_split('/[,\s%2C]+/', $coordString);
                        
                        if (count($coords) >= 2) {
                            $lat = trim($coords[0]);
                            $lng = trim($coords[1]);
                        }
                    }
                }
            }
        }

        if ($lat && $lng) {
            $location = $lat . ', ' . $lng;
            $address_data = $this->helpers->getLocationDataByCoords($lat, $lng) ?? [];
        }


        $images = [];
        // Find the gallery section by ID
        $galleryContainer = $html->find('#gallery', 0);

        if ($galleryContainer) {
            // Find all divs with data-framer-background-image-wrapper attribute
            $imageWrappers = $galleryContainer->find('div[data-framer-background-image-wrapper="true"]');
            
            if ($imageWrappers && count($imageWrappers) > 0) {
                foreach ($imageWrappers as $wrapper) {
                    // Find the img element inside the wrapper
                    $imgElement = $wrapper->find('img', 0);
                    
                    if ($imgElement) {
                        // Try different ways to get the src
                        $imageUrl = $imgElement->src ?? $imgElement->getAttribute('src');
                        
                        if ($imageUrl) {
                            // Remove all parameters (everything after ?)
                            $cleanUrl = preg_replace('/\?.*$/', '', $imageUrl);
                            
                            // Add to images array if not already present
                            if (!empty($cleanUrl) && !in_array($cleanUrl, $images)) {
                                $images[] = $cleanUrl;
                            }
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

       $listing_id = $this->helpers->generateReferenceId();

        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $this->helpers->translateHtmlPreservingTags($descriptionHtml),
            "property_excerpt" => $translatedExcerpt,
            "price" => $price,
            "currency" => $currency,
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $location,
            "bedrooms" => $beds,
            "bathrooms" => $baths,
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
            "latitude" => $lat,
            "longitude" => $lng,
            "listing_id" => 'ALSBR-'.$listing_id,
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