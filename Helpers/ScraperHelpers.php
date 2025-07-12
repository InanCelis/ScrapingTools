<?php
require_once __DIR__ . '/../simple_html_dom.php';

class ScraperHelpers {
    public function getHtml(string $url): ?simple_html_dom {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 15,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) {
            echo "Failded HTML";
            return null;
        } 

        $dom = str_get_html($html);
        if ($dom) {
            return $dom;
        } else {
            echo "Failed DOM";
            return null;
        }
        
    }
}