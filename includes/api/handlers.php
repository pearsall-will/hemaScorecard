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

// END OF FILE /////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
