<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';

class RealEstateScraper {
    private string $baseUrl = "https://www.mexicanroofre.com";
    private array $propertyLinks = [];
    private array $scrapedData = [];

    public function __construct() {
        // Initialize the ApiSender with your actual API URL and token
        $this->apiSender = new ApiSender(true);
        $this->successUpload = 1;
    }

    public function run(int $pageCount = 1, int $limit = 0): void {
        $folder = __DIR__ . '/../ScrapeFile/MexicanRoofre';
        $outputFile = $folder . '/House3.json';

        // Create the folder if it doesn't exist
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Start a fresh JSON array
        file_put_contents($outputFile, "[");

        $propertyCounter = 0;

        for ($page = 55; $page <= $pageCount; $page++) {
            $url = $this->baseUrl . "/properties/house-type?page={$page}&sort_by=price-desc&web_page=properties";
            // $url = $this->baseUrl . "/properties/mexico/villa-type?sort_by=published_at-desc";
            
            echo "📄 Fetching page $page: $url\n";

            $html = $this->getHtml($url);
            if (!$html) {
                echo "⚠️ Failed to load page $page. Skipping...\n";
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
            $propertyHtml = $this->getHtml($url);
            if ($propertyHtml) {
                $this->scrapedData = []; // Clear for fresh property
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
        foreach ($html->find('a') as $a) {
            $href = $a->href ?? '';
            if (strpos($href, '/property/') !== false) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                $this->propertyLinks[] = $fullUrl;
            }
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function scrapePropertyDetails(simple_html_dom $html, $url): void {

        //======================================================================//
        $ownedBy = "Mexican Roof RE";
        $contactPerson = "Mexican Roof RE";
        $phone = "+52 1 55 7601 0387 / +52 1 55 4439 2517";
        $email = "mexicanroofre@gmail.com";
        //======================================================================//

        $summaryDetails = $this->extractSummaryDetails($html);
        $coords = $this->extractLatLngFromIframe($html);

        $titleRaw = trim($html->find('h1', 0)->plaintext ?? '');
        $title = $this->unofficialTranslate($titleRaw);

        $descriptionHtml = '';
        $infoBlock = $html->find('div.info', 0);
        if ($infoBlock) {
            $rawHtml = $infoBlock->innertext;
            $descriptionHtml = preg_replace(
                '#<font[^>]*style="vertical-align:\\s*inherit;"[^>]*>(.*?)</font>#is',
                '$1',
                $rawHtml
            );
        }

        $plainText = trim(strip_tags($descriptionHtml));
        $translatedExcerpt = $this->unofficialTranslate(substr($plainText, 0, 300));

        // $priceRaw = trim($html->find('.listing-type-price', 0)->plaintext ?? '');
        // $price = preg_replace('/[^\d]/', '', $priceRaw);

        $priceRaw = trim($html->find('.listing-type-price', 0)->plaintext ?? '');

        // Check if the price contains "US$" to determine if it's USD
        if (strpos($priceRaw, 'US$') !== false) {
            // Remove non-numeric characters, keep the digits, including commas and decimals
            $price = preg_replace('/[^\d.]/', '', $priceRaw); // Allow decimal point
            $currency = 'USD';
        } else {
            // If "US$" is not found, treat as MXN
            $price = preg_replace('/[^\d.]/', '', $priceRaw); // Allow decimal point
            $currency = 'MXN';
        }

        // Convert the price to a float, round it, and remove the decimal part
        $price = round((float)$price); // Round to the nearest integer

        $statusRaw = trim($html->find('.listing-type', 0)->plaintext ?? '');
        $statusTranslated = $this->unofficialTranslate($statusRaw);
        $status = ($statusTranslated === 'On sale') ? 'For Sale' : $statusTranslated;

        $locationBlock = $html->find('h2.location', 0);
        $property_area = $city = $state = '';

        if ($locationBlock) {
            $locationParts = $locationBlock->find('a');
            if (isset($locationParts[0])) $property_area = trim($locationParts[0]->plaintext);
            if (isset($locationParts[1])) $city = trim($locationParts[1]->plaintext);
            if (isset($locationParts[2])) $state = trim($locationParts[2]->plaintext);
        }

        $constructionSize = '';
        $constructionSizePrefix = '';
        $mainFeatures = $html->find('#main_features ul li');
        foreach ($mainFeatures as $li) {
            if (stripos($li->innertext, 'de construcción') !== false) {
                if (preg_match('/([\d,\.]+)\s*m²/i', $li->plaintext, $match)) {
                    $constructionSize = floatval(str_replace(',', '', $match[1]));
                    $constructionSizePrefix = "sqm";
                }
                break;
            }
        }

        $images = [];
        foreach (array_slice($html->find('.rsImg img'), 0, 10) as $img) {
            $src = $img->src ?? '';
            if ($src) {
                $cleanSrc = preg_replace('/\?version=\d+$/', '', $src); // Remove ?version=NUMBER
                $images[] = $cleanSrc;
            }
        }

        $features = [];
        $amenitiesBlock = $html->find('div.amenities.summary-section', 0);
        if ($amenitiesBlock) {
            foreach ($amenitiesBlock->find('ul li') as $li) {
                $text = trim(strip_tags($li->innertext));
                if ($text !== '') {
                    $features[] = $this->unofficialTranslate($text);
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
            "bedrooms" => $summaryDetails['bedrooms'],
            "bathrooms" => $summaryDetails['bathrooms'],
            "size" => $constructionSize,
            "size_prefix" => $constructionSizePrefix,
            "property_type" => $summaryDetails['property_type'],
            "property_status" => [$status],
            "property_address" => $property_area.', '.$city.', '. $state.', Mexico',
            "property_area" => $property_area,
            "city" => $city,
            "state" => $state,
            "country" => "Mexico",
            "zip_code" => "",
            "latitude" => $coords['latitude'],
            "longitude" => $coords['longitude'],
            "listing_id" => $summaryDetails['listing_id'],
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

    private function extractSummaryDetails(simple_html_dom $html): array {
        $details = [
            "property_type" => [],
            "bedrooms" => "",
            "bathrooms" => "",
            "listing_id" => ""
        ];

        foreach ($html->find('#summary ul li') as $li) {
            $label = strtolower(trim($li->find('span', 0)->plaintext ?? ''));
            $value = trim($li->find('strong', 0)->plaintext ?? '');

            if ($label && $value) {
                if (stripos($label, 'Tipo:') !== false) {
                    $details["property_type"][] = $value === 'Casa' ? 'House' : $this->unofficialTranslate($value); 
                } elseif (stripos($label, 'Recámaras:') !== false) {
                    preg_match('/\d+/', $value, $matches);
                    $details["bedrooms"] = $matches[0] ?? $value;
                } elseif (
                    (stripos($label, 'bathrooms') !== false || stripos($label, 'baños') !== false) &&
                    stripos($label, 'half') === false && 
                    stripos($label, 'medio') === false
                ) {
                    // Only assign if it's NOT half bathrooms
                    preg_match('/\d+/', $value, $matches);
                    $details["bathrooms"] = $matches[0] ?? $value;
                }elseif (stripos($label, 'ID:') !== false) {
                    $details["listing_id"] = $value;
                }
            }
        }

        return $details;
    }

    private function extractLatLngFromIframe(simple_html_dom $html): array {
        foreach ($html->find('[data-lazy-iframe-url]') as $element) {
            $iframeUrl = html_entity_decode($element->getAttribute('data-lazy-iframe-url') ?? '');
            if (preg_match('/[?&]q=([\d\.\-]+),([\d\.\-]+)/', $iframeUrl, $matches)) {
                return ['location' => $matches[1].', '. $matches[2],'latitude' => $matches[1], 'longitude' => $matches[2]];
            }
        }

        return ['location' => '', 'latitude' => '', 'longitude' => ''];
    }

    // private function unofficialTranslate($text, $to = 'en') {
    //     $text = urlencode($text);
    //     $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl={$to}&dt=t&q={$text}";
    //     $response = file_get_contents($url);
    //     $response = json_decode($response, true);
    //     return $response[0][0][0] ?? $text;
    // }

    private function unofficialTranslate($text, $to = 'en') {
        $encoded = urlencode($text);
        $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl={$to}&dt=t&q={$encoded}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            echo "⚠️ Failed to translate: $text\n";
            return $text;
        }

        $response = json_decode($response, true);
        return $response[0][0][0] ?? $text;
    }

    private function translateHtmlPreservingTags(string $html): string {
        $html = "<div>$html</div>";
        $translated = preg_replace_callback('/>([^<>]+)</', function ($matches) {
            $text = trim($matches[1]);
            if ($text === '') return '><';
            $translatedText = $this->unofficialTranslate($text);
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
