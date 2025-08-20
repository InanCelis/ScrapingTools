<?php
require_once __DIR__ . '/../simple_html_dom.php';
require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class AlSabr {
    private string $baseUrl = "https://al-sabr.com";
    private string $foldername = "AlSabr";
    private string $filename = "For-Sale.json";
    private array $propertyLinks = [];
    private array $scrapedData = [];
    private ApiSender $apiSender;
    private ScraperHelpers $helpers;
    private int $successCreated;
    private int $successUpdated;
    private bool $enableUpload = false;
    private bool $testingMode = true;

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
            $url = $this->baseUrl . "/properties-sale";
            
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
        if($this->testingMode) {
            file_put_contents('test.html', $html);
            return;
        }
        foreach ($html->find('.projectCardWrap_tabCardContentList__LXZBY a') as $a) {
            $href = $a->href ?? '';
            if (!empty($href)) {
                $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
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
        

        $script = $html->find('script#__NEXT_DATA__', 0);
        $jsonData = json_decode($script->innertext, true);
        $propertyListing = $jsonData['props']['pageProps']['projectDetailsData'] ?? null;

        $title = $propertyListing['attributes']['title'] ?? null;
        if(empty($title)) {
            echo "❌ Skipping property with invalid setup of html\n ";
            return; 
        }
        $this->scrapedData[] = [
            "property_title" => $title,
            // "property_description" => $this->helpers->translateHtmlPreservingTags($descriptionHtml),
            // "property_excerpt" => $translatedExcerpt,
            // "price" => $price,
            // "currency" => $currency,
            // "price_postfix" => "",
            // "price_prefix" => "",
            // "location" => $coords,
            // "bedrooms" => $bedrooms,
            // "bathrooms" => $bathrooms,
            // "size" => $size,
            // "size_prefix" => $size_prefix,
            // "property_type" => [$type],
            // "property_status" => [$status],
            // "property_address" => $address,
            // "property_area" => $area,
            // "city" => $city,
            // "state" => '',
            // "country" => $country,
            // "zip_code" => "",
            // "latitude" => $latitude,
            // "longitude" => $longitude,
            // "listing_id" => 'MyBali-'.$listing_id,
            // "agent_id" => "150",
            // "agent_display_option" => "agent_info",
            // "mls_id" => "",
            // "office_name" => "",
            // "video_url" => "",
            // "virtual_tour" => "",
            // "images" => $images,
            // "property_map" => "1",
            // "property_year" => "",
            // "additional_features" => "",
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

