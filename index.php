<?php
require_once 'Executable/BarrattHomes.php';
// require_once 'Executable/IdealHomePortugal.php';

// $scraper = new PHGreatScraper();
// $scraper->run(2); // You can increase the number of listings scraped


$scraper = new BarrattHomes();
// $scraper = new IdealHomePortugal();
$scraper->run(1);