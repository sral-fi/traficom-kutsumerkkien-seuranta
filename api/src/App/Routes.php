<?php

declare(strict_types=1);

// API root endpoints
$app->get('/',    'App\Controller\Home:getHelp');
$app->get('/api', 'App\Controller\Home:getHelp');

// Statistics
$app->get('/api/summary', 'App\Controller\Stats:getSummary');
$app->get('/api/stats',   'App\Controller\Stats:getStats');

// Change log
$app->get('/api/changes', 'App\Controller\Changes:getChanges');

// Callsign lookup
$app->get('/api/search', 'App\Controller\Search:getSearch');
