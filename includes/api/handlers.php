<?php
/*******************************************************************************
	API Route Handlers

	One function per resource. Each handler is responsible for validating
	its own params/gating and must end by calling apiRespond() or
	apiError() (both exit). Route params arrive as an ordered list of ints
	(see matchRoute() in api.php).

*******************************************************************************/

function apiListEvents(array $params){
// GET /v1/events
// Published/archived events only (see getEventList()'s 'old' status:
// isArchived OR any publishX flag set) — never the unfiltered default.

	$limit = apiQueryInt('limit', 0);

	$events = getEventList('old', $limit);

	apiRespond((object)$events);
}

/******************************************************************************/

function apiGetEvent(array $params){
// GET /v1/events/{id}

	$eventID = $params[0];
	apiRequireEventPublished($eventID);

	$event = array_merge(
		['eventID' => $eventID, 'eventName' => getEventName($eventID)],
		(array)getEventDates($eventID),
		(array)getEventLocation($eventID)
	);
	$event['description']  = getEventDescription($eventID);
	$event['isArchived']   = isEventArchived($eventID);
	$event['rosterPublished']   = isRosterPublished($eventID);
	$event['schedulePublished'] = isSchedulePublished($eventID);
	$event['matchesPublished']  = isMatchesPublished($eventID);

	apiRespond($event);
}

/******************************************************************************/

function apiGetEventTournaments(array $params){
// GET /v1/events/{id}/tournaments

	$eventID = $params[0];
	apiRequireEventPublished($eventID);

	$tournaments = getTournamentsFull($eventID);

	apiRespond((object)$tournaments);
}

/******************************************************************************/

function apiGetEventParticipants(array $params){
// GET /v1/events/{id}/participants
// getEventRoster() is session-coupled (reads $_SESSION['eventID']); seed it
// immediately before the call rather than threading it through as a param.

	$eventID = $params[0];
	apiRequireEventPublished($eventID);
	apiRequireRosterPublished($eventID);

	$_SESSION['eventID'] = $eventID;
	$roster = getEventRoster();

	apiRespond($roster ?: []);
}

/******************************************************************************/

function apiGetTournament(array $params){
// GET /v1/tournaments/{id}

	$tournamentID = $params[0];
	$eventID = apiRequireTournamentEvent($tournamentID);

	apiRespond([
		'tournamentID'   => $tournamentID,
		'eventID'        => $eventID,
		'tournamentName' => getTournamentName($tournamentID),
		'isPools'        => isPools($tournamentID),
		'isBrackets'     => isBrackets($tournamentID),
		'isTeams'        => isTeams($tournamentID),
		'isResultsOnly'  => isResultsOnly($tournamentID),
		'isFinalized'    => isFinalized($tournamentID),
	]);
}

/******************************************************************************/

function apiGetTournamentParticipants(array $params){
// GET /v1/tournaments/{id}/participants
// getTournamentRoster() only returns rosterID + school info, so enrich each
// entry with a display name via getFighterName() (small roster, cheap N+1).

	$tournamentID = $params[0];
	$eventID = apiRequireTournamentEvent($tournamentID);
	apiRequireRosterPublished($eventID);

	$isTeams = isTeams($tournamentID);
	$roster = (array)getTournamentRoster($tournamentID, 'rosterID');
	foreach($roster as $rosterID => &$fighter){
		$fighter['name'] = getFighterName($rosterID, null, null, $isTeams);
	}
	unset($fighter);

	apiRespond((object)$roster);
}

/******************************************************************************/

function apiGetTournamentPools(array $params){
// GET /v1/tournaments/{id}/pools?groupSet=

	$tournamentID = $params[0];
	$eventID = apiRequireTournamentEvent($tournamentID);
	apiRequireMatchesPublished($eventID);

	$groupSet = apiQueryInt('groupSet', 1);
	$pools = getPools($tournamentID, $groupSet);

	apiRespond($pools ?: []);
}

/******************************************************************************/

function apiGetTournamentPoolMatches(array $params){
// GET /v1/tournaments/{id}/pool-matches?groupSet=

	$tournamentID = $params[0];
	$eventID = apiRequireTournamentEvent($tournamentID);
	apiRequireMatchesPublished($eventID);

	$groupSet = apiQueryInt('groupSet', 1);
	$matches = getPoolMatches($tournamentID, null, $groupSet);

	apiRespond((object)$matches);
}

/******************************************************************************/

function apiGetTournamentBrackets(array $params){
// GET /v1/tournaments/{id}/brackets
// getBracketInformation() keys by bracket type (BRACKET_PRIMARY=1/
// BRACKET_SECONDARY=2, i.e. the eventGroups.groupNumber column) plus a
// top-level 'elimType'; each bracket's matches are fetched by its groupID.

	$tournamentID = $params[0];
	$eventID = apiRequireTournamentEvent($tournamentID);
	apiRequireMatchesPublished($eventID);

	$brackets = getBracketInformation($tournamentID);
	$elimType = $brackets['elimType'] ?? null;
	unset($brackets['elimType']);

	foreach($brackets as $bracketType => &$bracket){
		$bracket['matches'] = (object)getBracketMatchesByPosition($bracket['groupID']);
	}
	unset($bracket);

	apiRespond([
		'elimType' => $elimType,
		'brackets' => (object)$brackets,
	]);
}

/******************************************************************************/

function apiGetTournamentStandings(array $params){
// GET /v1/tournaments/{id}/standings?groupSet=&groupType=pool|finals

	$tournamentID = $params[0];
	$eventID = apiRequireTournamentEvent($tournamentID);
	apiRequireMatchesPublished($eventID);

	$groupSet = apiQueryInt('groupSet', 1);
	$groupType = apiQueryEnum('groupType', ['pool', 'finals'], 'pool');

	$standings = getTournamentStandings($tournamentID, $groupSet, $groupType);

	apiRespond($standings ?: []);
}

/******************************************************************************/

function apiRequireEventPublished(int $eventID){
// 404 (not 403) so an unpublished/hidden event's existence isn't revealed.

	if($eventID === (int)TEST_EVENT_ID || !isEventPublished($eventID)){
		apiError(404, 'not_found', 'Event not found');
	}
}

function apiRequireRosterPublished(int $eventID){

	if(!isRosterPublished($eventID)){
		apiError(404, 'not_found', 'Not found');
	}
}

function apiRequireMatchesPublished(int $eventID){

	if(!isMatchesPublished($eventID)){
		apiError(404, 'not_found', 'Not found');
	}
}

function apiRequireTournamentEvent(int $tournamentID): int {
// Resolves a tournament's parent event, 404ing if the tournament doesn't
// exist or its event isn't published. Centralizes the pattern shared by
// every /tournaments/{id}... handler.

	$eventID = (int)getTournamentEventID($tournamentID);
	if($eventID === 0){
		apiError(404, 'not_found', 'Tournament not found');
	}
	apiRequireEventPublished($eventID);

	return $eventID;
}

// END OF FILE /////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
