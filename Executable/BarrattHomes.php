<?php
require_once __DIR__ . '/../simple_html_dom.php';

class BarrattHomes {
    private string $baseUrl = "https://www.barratthomes.co.uk";
    private array $propertyLinks = [];
    private array $scrapedData = [];

    public function run(int $pageCount = 1, int $limit = 0): void {
        $folder = __DIR__ . '/../ScrapeFile/BarrattHomes';
        $outputFile = $folder . '/derbyshire.json';
        // $htmlTest =  $folder . '/Test.html';

        // Create the folder if it doesn't exist
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Start a fresh JSON array
        file_put_contents($outputFile, "[");

        $propertyCounter = 0;
        $pages = 0;
        for ($page = 1; $page <= $pageCount; $page++) {0;
            // $url = $this->baseUrl . "/properties/house-type?page={$page}&sort_by=price-desc&web_page=properties";
            $url = $this->baseUrl . "/new-homes/east-midlands/derbyshire/";
            
            echo "📄 Fetching page $page: $url\n";

            $html = file_get_html($url);
            if (!$html) {
                echo "⚠️ Failed to load page $page. Skipping...\n";
                continue;
            }
            $pages +=24;
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
                $this->scrapePropertyDetails($propertyHtml);

                if (!empty($this->scrapedData[0])) {
                    $jsonEntry = json_encode($this->scrapedData[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    file_put_contents($outputFile, ($propertyCounter > 0 ? "," : "") . "\n" . $jsonEntry, FILE_APPEND);
                    $propertyCounter++;
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
                $this->propertyLinks[] = $fullUrl;
            }
        }
        $this->propertyLinks = array_unique($this->propertyLinks);
    }

    private function scrapePropertyDetails(simple_html_dom $html): void {
        // $summaryDetails = $this->extractSummaryDetails($html);
        $coords = $this->extractLatLngFromIframe($html);

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


        $locationBlock = $html->find('h2.location', 0);
        $property_area = $city = $state = '';

        if ($locationBlock) {
            $locationParts = $locationBlock->find('a');
            if (isset($locationParts[0])) $property_area = trim($locationParts[0]->plaintext);
            if (isset($locationParts[1])) $city = trim($locationParts[1]->plaintext);
            if (isset($locationParts[2])) $state = trim($locationParts[2]->plaintext);
        }

      

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
            "property_type" => ["House"],
            "property_status" => ["For Sale"],
            "property_address" => $property_area.', '.$city.', '. $state.', United Kingdom',
            "property_area" => $property_area,
            "city" => $city,
            "state" => $state,
            "country" => "United Kingdom",
            "zip_code" => "",
            "latitude" => $coords['latitude'],
            "longitude" => $coords['longitude'],
            "listing_id" => "",
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
                    "fave_additional_feature_value" => "Harcourts Purba Bali"
                ],
                [
                    "fave_additional_feature_title" => "Website",
                    "fave_additional_feature_value" => "https://harcourtspurbabali.com/"
                ],
                [
                    "fave_additional_feature_title" => "Contact Person",
                    "fave_additional_feature_value" => "Dicky"
                ],
                [
                    "fave_additional_feature_title" => "Phone",
                    "fave_additional_feature_value" => "123"
                ],
                [
                    "fave_additional_feature_title" => "Email",
                    "fave_additional_feature_value" => "youremail@gmail.com"
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
