<?php
require_once 'Executable/RealEstateScraper.php';
// require_once 'Executable/BarrattHomes.php';
// require_once 'Executable/IdealHomePortugal.php';

// $scraper = new PHGreatScraper();
// $scraper->run(2); // You can increase the number of listings scraped


//Ideal Homes Portugal
// $scraper = new IdealHomePortugal();
// $scraper->run(1);

//Ideal Homes Portugal
// $scraper = new BarrattHomes();

// $eastMidlands = [
//     "/new-homes/east-midlands/derbyshire/",
//     "/new-homes/east-midlands/leicestershire/",
//     "/new-homes/east-midlands/lincolnshire/",
//     "/new-homes/east-midlands/northamptonshire/",
//     "/new-homes/east-midlands/nottinghamshire/",
//     "/new-homes/east-midlands/waterbeach/"
// ];

// $filename = "London";
// $london = [
//     "/search-results/?qloc=London%252C%2520UK&latLng=51.5072178%252C-0.1275862"
// ];
// $scraper->run($london, 2, $filename
// ); // Optional limit


$scraper = new RealEstateScraper();
$scraper->run(70);