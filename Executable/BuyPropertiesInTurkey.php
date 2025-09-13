<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class BuyPropertiesInTurkey {
    private string $baseUrl = "https://buypropertiesinturkey.com";
    private string $foldername = "BuyPropertiesInTurkey";
    private string $filename = "Properties2.json";
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
            $url = $this->baseUrl . "/search-results/?paged={$page}&listing_page_id=16859&use_radius=on&radius=50&type%5B%5D=apartment&type%5B%5D=hot-offers&type%5B%5D=investment&type%5B%5D=penthouse&type%5B%5D=villa&min-price=200&max-price=2500000";
            
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
        
        // Get total count of property links
        $totalLinks = count($this->propertyLinks);
        echo "📊 Total properties to scrape: {$totalLinks}\n\n";
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
        
        foreach ($html->find('#houzez_ajax_container h2.item-title a') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/property/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $this->propertyLinks[] = $fullUrl;
            }
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
       
        $ownedBy = "Buy Properties in Turkey";
        $contactPerson = "Elhamuddin";
        $phone = "+90 545 648 69 96";
        $email = "elham@buypropertiesinturkey.com";
        

        $title = trim($html->find('.page-title h1', 0)->plaintext ?? '');
        if(empty($title)) {
            echo "❌ Skipping property with invalid setup of html\n ";
            return; 
        }
        
        
        $descriptionElement = $html->find('#property-description-wrap .block-content-wrap', 0);

        if ($descriptionElement) {
            $descriptionHtml = $descriptionElement->innertext;
            
            // Remove all attributes except href and target
            $descriptionHtml = preg_replace('/\s+(?!href|target)[a-zA-Z-]+="[^"]*"/', '', $descriptionHtml);
            
            // Remove empty divs and unnecessary nested divs
            $descriptionHtml = preg_replace('/<div[^>]*>\s*<\/div>/', '', $descriptionHtml);
            $descriptionHtml = preg_replace('/<div[^>]*>(\s*<div[^>]*>)*/', '<div>', $descriptionHtml);
            $descriptionHtml = preg_replace('/(<\/div>\s*)*<\/div>/', '</div>', $descriptionHtml);
            
            // Remove social media paragraph
            $descriptionHtml = preg_replace('/<p>\s*<a[^>]*facebook[^>]*>.*?Youtube[^<]*<\/a>\s*<\/p>/s', '', $descriptionHtml);
            
            // Clean up extra spaces
            $descriptionHtml = preg_replace('/\s+/', ' ', $descriptionHtml);
            $descriptionHtml = trim($descriptionHtml);
            
            // Create clean excerpt
            $plainText = strip_tags($descriptionHtml);
            $plainText = preg_replace('/\s+/', ' ', $plainText);
            $plainText = trim($plainText);
            $translatedExcerpt = substr($plainText, 0, 300);
        }


        // Extract property details from the detail-wrap section
        $detailWrap = $html->find('#property-detail-wrap .detail-wrap', 0);

        // Initialize variables
        $listing_id = '';
        $price = '';
        $currency = '';
        $bathrooms = '';
        $bedroom = '';
        $property_status = [];
        $property_type = [];
        $year_built = '';
        $size = '';
        $size_prefix = '';

        if ($detailWrap) {
            $detailsHtml = $detailWrap->innertext;
            
            // Extract Property ID
            if (preg_match('/<strong>Property ID:<\/strong>\s*<span>([^<]+)<\/span>/', $detailsHtml, $matches)) {
                $listing_id = trim($matches[1]);
            }
            
            // Extract Price and Currency
            if (preg_match('/<strong>Price:<\/strong>\s*<span>([^<]+)<\/span>/', $detailsHtml, $matches)) {
                $priceText = trim($matches[1]);
                
                // Extract currency symbol
                if (preg_match('/([€$£¥₹])/', $priceText, $currencyMatches)) {
                    $currencySymbol = $currencyMatches[1];
                    
                    // Map symbol to currency code
                    $currencyMap = [
                        '€' => 'EUR',
                        '$' => 'USD',
                        '£' => 'GBP',
                    ];
                    
                    $currency = $currencyMap[$currencySymbol] ?? 'EUR';
                }
                
                // Extract numeric value
                if (preg_match('/[\d,]+/', $priceText, $priceMatches)) {
                    $price = (int)str_replace(',', '', $priceMatches[0]);
                }
            }
            
            // Extract Property Size
            if (preg_match('/<strong>Property Size:<\/strong>\s*<span>([^<]+)<\/span>/', $detailsHtml, $matches)) {
                $sizeText = trim($matches[1]);
                
                if (preg_match('/([\d,\.]+)\s*(.+)/', $sizeText, $sizeMatches)) {
                    $size = (int)str_replace(',', '', $sizeMatches[1]);
                    $rawPrefix = trim($sizeMatches[2]);
                    
                    // Convert m² to sqm
                    if ($rawPrefix === 'm²' || $rawPrefix === 'm2') {
                        $size_prefix = 'sqm';
                    } else {
                        $size_prefix = $rawPrefix;
                    }
                }
            }
            // Extract Bedroom - more flexible
            if (preg_match('/<strong>Bedroom(?:s)?:<\/strong>\s*<span>([^<]+)<\/span>/i', $detailsHtml, $matches)) {
                $bedroom = (int)trim($matches[1]);
            }
            
            if (preg_match('/<strong>Bathroom(?:s)?:<\/strong>\s*<span>([^<]*)<\/span>/i', $detailsHtml, $matches)) {
                $bathroomText = trim($matches[1]);
                if (!empty($bathroomText) && is_numeric($bathroomText)) {
                    $bathrooms = (int)$bathroomText;
                }
            }
            
            // Extract Year Built
            if (preg_match('/<strong>Year Built:<\/strong>\s*<span>([^<]+)<\/span>/', $detailsHtml, $matches)) {
                $year_built = (int)trim($matches[1]);
            }
            
            // Allowed values
            $status_allowed = ['For Sale'];
            $allowed_type = ['Villa', 'Condo', 'Apartment', 'House', 'Penthouse', 'Casa', 'Studio','Studios', 'Home'];

            if (preg_match('/<strong>Property Type:<\/strong>\s*<span>([^<]+)<\/span>/', $detailsHtml, $matches)) {
                $typeText = trim($matches[1]);
                // Split by comma if multiple types
                $types = array_map('trim', explode(',', $typeText));
                
                // Filter only allowed types
                foreach ($types as $type) {
                    if (in_array($type, $allowed_type)) {
                        $property_type[] = $type;
                    }
                }
            }

            if (preg_match('/<strong>Property Status:<\/strong>\s*<span>([^<]+)<\/span>/', $detailsHtml, $matches)) {
                $statusText = trim($matches[1]);
                // Split by comma if multiple statuses
                $statuses = array_map('trim', explode(',', $statusText));
                
                // Filter only allowed statuses
                foreach ($statuses as $status) {
                    if (in_array($status, $status_allowed)) {
                        $property_status[] = $status;
                    }
                }
            }
        }

        // Check if price extraction failed or resulted in zero/invalid price
        if (empty($price) || !is_numeric($price) || (int)$price <= 0) {
            echo "❌ Skipping property with invalid price. Extracted value: '$price'\n";
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

        $images = [];

        // Extract images from lightbox gallery
        $lightboxGallery = $html->find('#lightbox-slider-js', 0);

        if ($lightboxGallery) {
            // Find all img tags within the slider, excluding cloned slides
            $imageElements = $lightboxGallery->find('.slick-slide:not(.slick-cloned) img');
            
            if ($imageElements && count($imageElements) > 0) {
                foreach ($imageElements as $imgElement) {
                    // Get the src attribute
                    $imageUrl = $imgElement->getAttribute('src');
                    
                    if ($imageUrl) {
                        // First priority: scaled images
                        if (strpos($imageUrl, '-scaled.jpg') !== false || strpos($imageUrl, '-scaled.png') !== false) {
                            $images[] = $imageUrl;
                        }
                        // Second priority: any other image
                        else {
                            $images[] = $imageUrl;
                        }
                        
                        // Stop after collecting 10 images
                        if (count($images) >= 10) {
                            break;
                        }
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
        // Extract features from property-features-wrap
        $featuresWrap = $html->find('#property-features-wrap .block-content-wrap', 0);

        if ($featuresWrap) {
            // Find all anchor tags within list items
            $featureLinks = $featuresWrap->find('li a');
            
            foreach ($featureLinks as $link) {
                $featureText = trim($link->plaintext);
                
                if (!empty($featureText)) {
                    $features[] = $featureText;
                }
            }
        }

        $video_url = '';
        // Extract video URL from property-video-wrap
        $videoWrap = $html->find('#property-video-wrap', 0);

        if ($videoWrap) {
            // Find iframe within the video wrap
            $iframe = $videoWrap->find('iframe', 0);
            
            if ($iframe) {
                $src = $iframe->getAttribute('src');
                
                if ($src) {
                    // Convert YouTube embed URL to regular YouTube URL
                    if (strpos($src, 'youtube.com/embed/') !== false) {
                        // Extract video ID from embed URL
                        $videoId = '';
                        if (preg_match('/youtube\.com\/embed\/([^?&]+)/', $src, $matches)) {
                            $videoId = $matches[1];
                            $video_url = 'https://www.youtube.com/watch?v=' . $videoId;
                        }
                    }
                    // Handle other video platforms or direct URLs
                    else {
                        $video_url = $src;
                    }
                }
            }
        }


        $latitude = '';
        $longitude = '';

        // Extract coordinates from JavaScript variable
        $scriptElements = $html->find('script');

        foreach ($scriptElements as $script) {
            $scriptContent = $script->innertext;
            
            // Look for houzez_single_property_map variable
            if (strpos($scriptContent, 'houzez_single_property_map') !== false) {
                
                // Extract latitude
                if (preg_match('/"lat":\s*"([^"]+)"/', $scriptContent, $latMatches)) {
                    $latitude = trim($latMatches[1]);
                }
                
                // Extract longitude
                if (preg_match('/"lng":\s*"([^"]+)"/', $scriptContent, $lngMatches)) {
                    $longitude = trim($lngMatches[1]);
                }
                
                // Break after finding the right script
                if (!empty($latitude) && !empty($longitude)) {
                    break;
                }
            }
        }

        // Convert to float for proper storage
        if (!empty($latitude)) {
            $latitude = (float)$latitude;
        }
        if (!empty($longitude)) {
            $longitude = (float)$longitude;
        }


        if ($latitude && $longitude) {
            $location = $latitude . ', ' . $longitude;
            $address_data = $this->helpers->getLocationDataByCoords($latitude, $longitude) ?? [];
        }

        $area = '';
        // Extract area from the detail list
        $areaElement = $html->find('li.detail-area', 0);

        if ($areaElement) {
            $spanElement = $areaElement->find('span', 0);
            if ($spanElement) {
                $area = trim($spanElement->plaintext);
            }
        }

        // Alternative method if the above doesn't work
        if (empty($area)) {
            $listElements = $html->find('ul.list-3-cols li');
            foreach ($listElements as $li) {
                $strongElement = $li->find('strong', 0);
                if ($strongElement && trim($strongElement->plaintext) === 'Area') {
                    $spanElement = $li->find('span', 0);
                    if ($spanElement) {
                        $area = trim($spanElement->plaintext);
                        break;
                    }
                }
            }
        }

        $address_parts = [];
        if (!empty($area)) {
            $address_parts[] = $area;
        }
        $address_parts[] = $address_data['address'];
        $final_address = !empty($address_parts) ? implode(', ', $address_parts) : '';


        $this->scrapedData[] = [
            "property_title" => $title,
            "property_description" => $this->helpers->translateHtmlPreservingTags($descriptionHtml),
            "property_excerpt" => $translatedExcerpt,
            "price" => $price,
            "currency" => $currency,
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $location,
            "bedrooms" => $bedroom,
            "bathrooms" => $bathrooms,
            "size" => $size,
            "size_prefix" => $size_prefix,
            "property_type" => $property_type,
            "property_status" => $property_status,
            "property_address" => $final_address,
            "property_area" => $area,
            "city" => $address_data['city'],
            "state" => $address_data['state'],
            "country" => $address_data['country'],
            "zip_code" => $address_data['postal_code'],
            "latitude" => $latitude,
            "longitude" => $longitude,
            "listing_id" => 'BPT-'.$listing_id,
            "agent_id" => "150",
            "agent_display_option" => "agent_info",
            "mls_id" => "",
            "office_name" => "",
            "video_url" => $video_url,
            "virtual_tour" => "",
            "images" => $images,
            "property_map" => "1",
            "property_year" => $year_built,
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

