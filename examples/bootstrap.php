<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

putenv('CONVOY_TRACE=' . (getenv('CONVOY_TRACE') ?: '1'));
