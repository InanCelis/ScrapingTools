<?php

#########################################################

## MYBALI
// require_once 'Executable/AlSabr.php';
// $scraper = new AlSabr('src/HTML/AlSabr.html');
// $scraper->run();
#########################################################

## Ideal Homes Portugal
// require_once 'Executable/IdealHomePortugal.php';
// $scraper = new IdealHomePortugal();
// $scraper->run(1,3);

#########################################################

## Luxury Estate Turkey
// require_once 'Executable/LuxuryEstateTurkey.php';
// $scraper = new LuxuryEstateTurkey();
// $scraper->run();

#########################################################

## Marbella Realty Group
require_once 'Executable/MarbellaRealtyGroup.php';
$scraper = new MarbellaRealtyGroup();
$scraper->run();