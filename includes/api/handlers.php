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

// END OF FILE /////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
