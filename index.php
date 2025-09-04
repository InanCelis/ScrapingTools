<?php
require_once 'vendor/autoload.php';
########################################################

## PH GREAT
// require_once 'Executable/PHGreateScraper.php';
// $scraper = new PHGreatScraper();
// $scraper->run(2); // You can increase the number of listings 

#########################################################

## Ideal Homes Portugal
// require_once 'Executable/IdealHomePortugal.php';
// $scraper = new IdealHomePortugal();
// $scraper->run(1);

#########################################################

##  BARRATS HOMES
// require_once 'Executable/BarrattHomes.php';
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

#########################################################

## MEXICAN ROOF
//require_once 'Executable/RealEstateScraper.php';
// $scraper = new RealEstateScraper();
// $scraper->run(70);

#########################################################

## BLUESKY HOMES
// require_once 'Executable/BlueskyHouses.php';
// $scraper = new BlueskyHouses();
// $scraper->run(94);

#########################################################

## MRESIDENCE
require_once 'Executable/MResidence.php';
$scraper = new MResidence();
$scraper->run(10);

#########################################################

## Marbella Realty Group
// require_once 'Executable/MarbellaRealtyGroup.php';
// $scraper = new MarbellaRealtyGroup();
// $scraper->run(2);

#########################################################

## MYBALI
// require_once 'Executable/MyBali.php';
// $scraper = new MyBali();
// $scraper->run(4);

#########################################################

## DAR GLOBAL
// require_once 'Executable/DarGlobal.php';
// $scraper = new DarGlobal();
// $scraper->run(1);

#########################################################

## AL Sabr
// require_once 'Executable/AlSabr.php';
// $scraper = new AlSabr();
// $scraper->run(1);

#########################################################

## Luxury Estate Turkey
// require_once 'Executable/LuxuryEstateTurkey.php';
// $scraper = new LuxuryEstateTurkey();
// $scraper->run(75);

#########################################################