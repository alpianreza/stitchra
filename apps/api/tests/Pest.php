<?php

use Tests\TestCase;

// Fixture bersama (dimuat sekali — jangan require antar file test)
require_once __DIR__.'/Helpers/ErpFixtures.php';

uses(TestCase::class)->in('Feature');
