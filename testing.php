<?php

#########################################################

## MYBALI
require_once 'Executable/AlSabr.php';
$scraper = new AlSabr('src/HTML/AlSabr.html');
$scraper->run();
#########################################################

## Ideal Homes Portugal
// require_once 'Executable/IdealHomePortugal.php';
// $scraper = new IdealHomePortugal();
// $scraper->run(1,3);