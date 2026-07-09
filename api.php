<?php
/*******************************************************************************
	Read-Only REST API — Front Controller

	Dependency-free: plain PHP routing over PATH_INFO, no framework. Works
	under `php -S` with no rewrite rules, e.g.:

		GET /api.php/v1/events
		GET /api.php/v1/events/1/tournaments

	GET only — every other method is rejected with 405. Routes are added
	incrementally; see the plan doc for the full resource map.

*******************************************************************************/

require_once(__DIR__.'/includes/api_bootstrap.php');
require_once(__DIR__.'/includes/api/handlers.php');

// ---- Method guard ----------------------------------------------------------
if($_SERVER['REQUEST_METHOD'] !== 'GET'){
	apiError(405, 'method_not_allowed', 'Only GET is supported', ['Allow' => 'GET']);
}

// ---- Parse PATH_INFO into segments -----------------------------------------
// e.g. /api.php/v1/events/1/tournaments -> ['v1','events','1','tournaments']
$path = $_SERVER['PATH_INFO'] ?? '';
$segments = array_values(array_filter(explode('/', $path), 'strlen'));

// ---- Version guard ----------------------------------------------------------
$version = array_shift($segments);
if($version !== 'v1'){
	apiError(404, 'not_found', 'Unknown or missing API version');
}

// ---- Route table --------------------------------------------------------
// [patternSegments, handlerFunctionName]. '{id}' captures a positive int.
$routes = [
	[['events'], 'apiListEvents'],
	[['events', '{id}'], 'apiGetEvent'],
	[['events', '{id}', 'tournaments'], 'apiGetEventTournaments'],
	[['events', '{id}', 'participants'], 'apiGetEventParticipants'],
	[['tournaments', '{id}'], 'apiGetTournament'],
	[['tournaments', '{id}', 'participants'], 'apiGetTournamentParticipants'],
	[['tournaments', '{id}', 'pools'], 'apiGetTournamentPools'],
	[['tournaments', '{id}', 'pool-matches'], 'apiGetTournamentPoolMatches'],
	[['tournaments', '{id}', 'brackets'], 'apiGetTournamentBrackets'],
	[['tournaments', '{id}', 'standings'], 'apiGetTournamentStandings'],
];

// ---- Dispatch -------------------------------------------------------------
foreach($routes as [$pattern, $handler]){
	$params = apiMatchRoute($pattern, $segments);
	if($params !== null){
		$handler($params);
		exit; // unreachable — handlers end in apiRespond()/apiError()
	}
}

apiError(404, 'not_found', 'No such resource');

/******************************************************************************/

function apiMatchRoute(array $pattern, array $segments): ?array {
// Matches literal segments and '{id}' captures (positive integers only).
// Returns an ordered list of captured ints, or null if no match.

	if(count($pattern) !== count($segments)){
		return null;
	}

	$params = [];
	foreach($pattern as $i => $token){
		if($token === '{id}'){
			if(!ctype_digit($segments[$i]) || (int)$segments[$i] < 1){
				return null;
			}
			$params[] = (int)$segments[$i];
		} elseif($token !== $segments[$i]){
			return null;
		}
	}
	return $params;
}

// END OF FILE /////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
