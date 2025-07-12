<?php
require_once __DIR__ . '/../simple_html_dom.php';

class PHGreatScraper {
    private string $baseUrl = "https://phgreatre.kw.com";
    private array $propertyLinks = [];
    private string $outputFile = __DIR__ . '/property_titles.json';

    public function run(int $pageCount = 1): void {
        $this->collectPropertyLinks($pageCount);
        
        if (empty($this->propertyLinks)) {
            echo "⚠️ No property links found. Check selectors or website structure.\n";
            return;
        }
        
        echo "Found " . count($this->propertyLinks) . " property links\n";
        $titles = $this->scrapeTitles();
        
        file_put_contents($this->outputFile, json_encode($titles, JSON_PRETTY_PRINT));
        echo "✅ Saved " . count($titles) . " titles to {$this->outputFile}\n";
    }

    private function collectPropertyLinks(int $pageCount): void {
        for ($page = 1; $page <= $pageCount; $page++) {
            $url = $this->baseUrl . "/search/sale?page=2";
            echo "Fetching page {$page}: {$url}\n";
            
            $html = file_get_html($url);
            
            if (!$html) {
                echo "⚠️ Failed to load page $page\n";
                continue;
            }

            // Debug: Save HTML to check structure
            file_put_contents(__DIR__ . "/debug_page_{$page}.html", $html->save());

            // Try multiple selectors
            $selectors = [
                'a.property-link',
                'a[href*="/property/"]',
                '.property-card a[href]',
                '.listing-item a[href]'
            ];
            
            $linksFound = 0;
            foreach ($selectors as $selector) {
                foreach ($html->find($selector) as $a) {
                    $href = trim($a->href);
                    if (!empty($href)) {
                        $fullUrl = strpos($href, 'http') === 0 ? $href : $this->baseUrl . $href;
                        if (!in_array($fullUrl, $this->propertyLinks)) {
                            $this->propertyLinks[] = $fullUrl;
                            $linksFound++;
                        }
                    }
                }
                if ($linksFound > 0) break; // Stop if we found links with this selector
            }
            
            echo "Found {$linksFound} links on page {$page}\n";
            sleep(2); // Increased delay
        }
    }

    private function scrapeTitles(): array {
        $titles = [];
        $total = count($this->propertyLinks);
        
        foreach ($this->propertyLinks as $i => $url) {
            echo "Scraping {$url} (" . ($i+1) . "/{$total})\n";
            
            $html = file_get_html($url);
            if (!$html) {
                echo "⚠️ Failed to load property page\n";
                continue;
            }

            // Try multiple title selectors
            $titleSelectors = [
                'h1',
                '.property-title',
                '.listing-title',
                'title'
            ];
            
            $title = 'No title found';
            foreach ($titleSelectors as $selector) {
                if ($element = $html->find($selector, 0)) {
                    $title = trim($element->plaintext);
                    break;
                }
            }
            
            $titles[] = [
                'url' => $url,
                'title' => $title
            ];
            
            sleep(2); // Increased delay
        }
        
        return $titles;
    }
}