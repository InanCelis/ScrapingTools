<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';

class BarrattHomes {
    private string $baseUrl = "https://www.barratthomes.co.uk";
    private array $propertyLinks = [];
    private array $scrapedData = [];
    private int $successUpload;

    public function __construct() {
        // Initialize the ApiSender with your actual API URL and token
        $this->apiSender = new ApiSender(true);
        $this->successUpload = 1;
    }

    public function run(array $urlsToRun = [], int $limit = 0, string $filename): void {
        $folder = __DIR__ . '/../ScrapeFile/BarrattHomes';
        $outputFile = $folder . '/'.$filename.'.json';
        // $htmlTest =  $folder . '/Test.html';

        // Create the folder if it doesn't exist
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Start a fresh JSON array
        file_put_contents($outputFile, "[");

        $propertyCounter = 0;
        // Process each URL in the array
        foreach ($urlsToRun as $url) {
            $url = $this->baseUrl .$url;
            echo "📄 Fetching URL: $url\n";

            $html = file_get_html($url);
            if (!$html) {
                echo "⚠️ Failed to load URL: $url. Skipping...\n";
                continue;
            }
            
            $this->extractPropertyLinks($html);
        }

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
                    if ($result['success']) {
                        echo "✅ Success after {$result['attempts']} attempt(s)\n Uploaded # " .$this->successUpload++. "\n";
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
        foreach ($html->find('.location-list__container a') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/new-homes/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $this->propertyLinks[] = str_replace('&#39;', "'", $fullUrl);
            }
        }
        if (empty($this->propertyLinks)) {
            $fallbackHtml = str_get_html($this->getLondonHtmlFallback());
            
            foreach ($fallbackHtml->find('.results__item a[href*="/new-homes/"]') as $a) {
                $href = $a->href ?? '';
                if (!empty($href)) {
                    $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                    $this->propertyLinks[] = str_replace('&#39;', "'", $fullUrl);
                }
            }
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function getLondonHtmlFallback(): string {
        $londonHtmlPath = __DIR__ . '/../src/barratthomes/London.html';
        
        if (!file_exists($londonHtmlPath)) {
            throw new RuntimeException("London.html fallback file not found at: " . $londonHtmlPath);
        }
        
        return file_get_contents($londonHtmlPath);
    }
    private function extractDevelopmentInfo(simple_html_dom $html): array {
        $default = [
            'development_id' => '',
            'development_status' => ''
        ];

        $developmentMeta = $html->find('meta[name="development"]', 0);
        if (!$developmentMeta || empty($developmentMeta->content)) {
            return $default;
        }

        // Fix common JSON issues in the content
        $content = html_entity_decode($developmentMeta->content);
        $content = str_replace("'", '"', $content); // Replace single quotes with double quotes
        $content = preg_replace('/(\w+):/', '"$1":', $content); // Wrap keys in quotes

        try {
            $devData = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON: ' . json_last_error_msg());
            }

            return [
                'development_id' => strtoupper($devData['developmentID'] ?? ''),
                'development_status' => $devData['developmentStatus'] ?? ''
            ];
        } catch (Exception $e) {
            error_log("Failed to parse development meta: " . $e->getMessage());
            return $default;
        }
    }
  
    private function scrapePropertyDetails(simple_html_dom $html, $url): void {
        //======================================================================//
        $ownedBy = "Barratt Homes";
        $contactPerson = "Barratt Homes";
        $phone = "03018173";
        $email = "customer.care@barrattredrow.co.uk";
        //======================================================================//
        $coords = $this->extractLatLngFromIframe($html);


        $devInfo = $this->extractDevelopmentInfo($html);
        $status = $devInfo['development_status'] ?? ''; // Add null coalescing operator for safety

        // price
        $price = '';
        $bedroom = '';
        $details = $html->find('div.marketing-header__details', 0);
        if ($details) {
            foreach ($details->find('li.icon-list__item') as $item) {
                $text = html_entity_decode(trim($item->plaintext)); // decode &#163; to £
                if (strpos($text, '£') !== false) {
                    preg_match('/£([\d,]+)/', $text, $matches);
                    if (isset($matches[1])) {
                        $price = str_replace(',', '', $matches[1]); // output: 239995
                        break;
                    }
                }
                // Get bedroom
                if (stripos($text, 'bedroom') !== false && $bedroom == '') {
                    preg_match('/\b(\d+)/', $text, $matches);
                    if (isset($matches[1])) {
                        $bedroom = $matches[1]; // gets the first number
                    }
                }
            }
        }

        $allowedStatuses = ['For Sale', 'Coming Soon'];

        // Check if status exists and is in allowed statuses (case-insensitive)
        if (empty($status) || !in_array(strtolower($status), array_map('strtolower', $allowedStatuses))) {
            echo "❌ Skipping property with status: $status\n";
            return; // Exit the function without scraping
        }
        if(empty($price)) {
            echo "❌ Skipping property with no price: $price\n";
            return; // Exit the function without scraping
        }

        echo "✅ Status OK: $status\n"; // Only reaches here if status is valid
        
       // property_title
        $titleElement = $html->find('h1.marketing-heading', 0);  // Correct selector for h1 with h3 class
        $items_loc = $html->find('ul.breadcrumb__list li.breadcrumb__item');
        $items_loc_text = " in ".trim($items_loc[count($items_loc) - 2]->find('.breadcrumb__item-link', 0)->plaintext);
        $title = $titleElement ? trim($titleElement->plaintext).$items_loc_text : 'No title found';

        //property_description
        $descriptionHtml = '';
        $infoBlock = $html->find('div.marketing-copy__content', 0);
        if ($infoBlock) {
            // Remove the <a> tag (link)
            foreach ($infoBlock->find('a') as $a) {
                $a->outertext = '';
            }

            // Remove the <button> tag
            foreach ($infoBlock->find('button') as $button) {
                $button->outertext = '';
            }

            // Optionally remove nested divs (if you want to avoid div wrappers inside content)
            foreach ($infoBlock->find('div') as $div) {
                $div->outertext = $div->innertext;
            }

            $rawHtml = $infoBlock->innertext;

            // Optional: Clean up unwanted <font> styling if needed
            $descriptionHtml = preg_replace(
                '#<font[^>]*style="vertical-align:\\s*inherit;"[^>]*>(.*?)</font>#is',
                '$1',
                $rawHtml
            );
        }
        // Append area information content
        $areaInfoBlock = $html->find('div.area-information__container', 0);
        if ($areaInfoBlock) {
            $descriptionHtml .= $areaInfoBlock->innertext;
        }

        // property_excerpt
        $plainText = trim(strip_tags($descriptionHtml));
        $translatedExcerpt = substr($plainText, 0, 300);

        


        $addressBlock = $html->find('div.marketing-header__address div.address', 0);
        $property_area = $city = $state = $zip_code = $country = '';
        $country = 'United Kingdom';
        if ($addressBlock) {
            $addressText = trim($addressBlock->plaintext);
            $addressParts = array_map('trim', explode(',', $addressText));
            
            // Get the last 4 parts (pad with empty strings if needed)
            $lastFour = array_slice(array_pad($addressParts, -4, ''), -4);
            
            // Assign components (handles cases with fewer than 4 parts)
            $area = $lastFour[0] ?? '';
            $city = $lastFour[1] ?? '';
            $state = $lastFour[2] ?? '';
            $zip_code = $lastFour[3] ?? '';
            
            $city = $state == 'London' ? 'London' : $city;
            $state = $city == 'London' ? 'England' : $state;
            // Clean UK postcode format
            $full_address = implode(', ', array_filter([
                $area,
                $city,
                $state,
                $zip_code,
                $country
            ]));
        }

        $listing_id = $devInfo['development_id'];

        // Images
        $images = [];

        // Find all img elements within the marketing-header__carousel
        $carousel = $html->find('div.marketing-header__carousel', 0);
        if ($carousel) {
            foreach ($carousel->find('img.image__lazy') as $img) {
                // Get the data-src attribute which contains multiple image URLs
                $srcSet = $img->getAttribute('data-src');
                if (!empty($srcSet)) {
                    // Split the srcset and take the first URL
                    $urls = explode('|', $srcSet);
                    $src = trim($urls[0]);
                    
                    // Remove ALL parameters from URL
                    $cleanSrc = preg_replace('/\?.*$/', '', $src);
                    
                    if (!empty($cleanSrc)) {
                        $images[] = $cleanSrc;
                    }
                }
            }
        }

        // Alternative approach if we still need more images
        if (count($images) < 10) {
            foreach ($html->find('img[data-src]') as $img) {
                $srcSet = $img->getAttribute('data-src');
                if (!empty($srcSet)) {
                    $urls = explode('|', $srcSet);
                    $src = trim($urls[0]);
                    
                    // Remove ALL parameters from URL
                    $cleanSrc = preg_replace('/\?.*$/', '', $src);
                    
                    if (!empty($cleanSrc)) {
                        $images[] = $cleanSrc;
                    }
                }
                if (count($images) >= 10) break;
            }
        }

        // Remove duplicates while preserving order and limit to 10
        $images = array_slice(array_values(array_unique($images)), 0, 10);


        $features = [];
        $amenitiesBlock = $html->find('div.amenities.summary-section', 0);
        if ($amenitiesBlock) {
            foreach ($amenitiesBlock->find('ul li') as $li) {
                $text = trim(strip_tags($li->innertext));
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
            "currency" => "GBP",
            "price_postfix" => "",
            "price_prefix" => "",
            "location" => $coords['location'],
            "bedrooms" => $bedroom,
            "bathrooms" => "",
            "size" => "",
            "size_prefix" => "",
            "property_type" => ["House"],
            "property_status" => [$status],
            "property_address" => $full_address,
            "property_area" => $area,
            "city" => $city,
            "state" => $state,
            "country" => $country,
            "zip_code" => $zip_code,
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
                    "fave_additional_feature_value" => "{$url}"
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


    private function extractLatLngFromIframe(simple_html_dom $html): array {
        // Look for iframe with class "map__iframe"
        foreach ($html->find('iframe.map__iframe') as $element) {
            $iframeUrl = html_entity_decode($element->getAttribute('src') ?? '');
            if (preg_match('/[?&]q=([\d\.\-]+),([\d\.\-]+)/', $iframeUrl, $matches)) {
                return [
                    'location' => $matches[1] . ', ' . $matches[2],
                    'latitude' => $matches[1],
                    'longitude' => $matches[2]
                ];
            }
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
