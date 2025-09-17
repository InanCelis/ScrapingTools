<?php
require_once 'vendor/autoload.php';


## Draft Properties
require_once 'Executable/DraftProperties.php';
$scraper = new DraftProperties();
$scraper->run("Marbella Realty Group", 0, 2050);