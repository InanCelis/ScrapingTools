<?php 

require_once __DIR__ . '/../Api/ApiSender.php';
require_once __DIR__ . '/../Helpers/ScraperHelpers.php';

class DraftProperties {
    private ApiSender $apiSender;
    private ScraperHelpers $helpers;
    private array $propertyLinks = [];

    public function __construct() {
        $this->apiSender = new ApiSender(true);
        $this->helpers = new ScraperHelpers();
    }

    public function run($owner, int $from, int $to): void {
        $result = $this->apiSender->getPropertyLinks($owner, $from, $to);

        if ($result['success']) {
            $this->propertyLinks = array_unique($result["links"]);
            echo "🔗 Retrieved " . count($this->propertyLinks) . " property links from API\n";
        } else {
            echo "❌ Failed to get property links: " . $result['error'] . "\n";
            echo "⚠️ Falling back to original scraping method if needed\n";
            $this->propertyLinks = []; // Initialize as empty array
        }


        $totalLinks = count($this->propertyLinks);
        echo "📊 Total properties to scrape: {$totalLinks}\n\n";

        foreach($this->propertyLinks as $url) {
            // $this->helpers->updatePostToDraft($url);
            echo $url . "\n";
        }
    }


}