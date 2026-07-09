<?php
/*******************************************************************************
	Configuration File

	Defines constants
	Includes function libraries
	Connects to database
	Establishes proper session values
	Runs the POST processing function

*******************************************************************************/

// Initialize Session //////////////////////////////////////////////////////////

	initializeSession();

// System Constants ////////////////////////////////////////////////////////////

	require_once(__DIR__.'/constants.php');

// Includes ////////////////////////////////////////////////////////////////////

require_once(BASE_URL.'includes/function_lib.php');

// Database Connection /////////////////////////////////////////////////////////

$conn = connectToDB();

// Set Session Values //////////////////////////////////////////////////////////

// Set the Permissions
	setPermissions();

// Set tournament ID if there is only one tournament in the event
	if($_SESSION['eventID'] != null){

		$_SESSION['eventName'] = getEventName($_SESSION['eventID']);

		if($_SESSION['tournamentID'] == null){
			$sql = "SELECT tournamentID
					FROM eventTournaments
					WHERE eventID = {$_SESSION['eventID']}";
			$tournamentIDs = mysqlQuery($sql, SINGLES, 'tournamentID');

			if(count($tournamentIDs) == 1){
				$_SESSION['tournamentID'] = $tournamentIDs[0];
			}
		}
	} else {
		$_SESSION['tournamentID'] == null;
	}

// Pool Set
	if(!isset($_SESSION['groupSet'])){$_SESSION['groupSet'] = 1;}

// Name mode  -- this MUST go before processPostData
	$defaults = getEventDefaults($_SESSION['eventID']);

	switch(@$defaults['nameDisplay']){
		case 'lastName':
			$nameMode = 'lastName';
			$nameMode2 = 'firstName';
			break;
		case 'firstName':
		default:
			$nameMode = 'firstName';
			$nameMode2 = 'lastName';
			break;
	}

	define("NAME_MODE", $nameMode);
	define("NAME_MODE_2", $nameMode2);

// Is Teams Mode -- MUST go before processPostData
	if(isset($_SESSION['tournamentID']) && $_SESSION['tournamentID'] != null){
		if(isTeams($_SESSION['tournamentID'])){
			define("IS_TEAMS",true);
		}
	}
	if(!defined('IS_TEAMS')){ define("IS_TEAMS", false); }

// Process POST Data ///////////////////////////////////////////////////////////

	processPostData();

// Define Constants Based on DB ////////////////////////////////////////////////

// Tournament Specific Constants
	if($_SESSION['tournamentID'] != null){
		$tournamentID = $_SESSION['tournamentID'];
		$sql = "SELECT isFinalized, isTeams, logicMode, formatID
				FROM eventTournaments
				WHERE tournamentID = {$tournamentID}";
		$tSettings = mysqlQuery($sql, SINGLE);

	// Tournament Concluded
		if($tSettings['isFinalized'] == 1){
			define("LOCK_TOURNAMENT", 'disabled');
		}

	// Use timer in the matches
		if($tSettings['logicMode'] != ''){
			define("LOGIC_MODE", $tSettings['logicMode']);
		}

	// Tournament format
		$_SESSION['formatID'] = $tSettings['formatID'];

	}
	if(!defined('LOCK_TOURNAMENT')){ define("LOCK_TOURNAMENT", ''); }
	if(!defined('LOGIC_MODE')){ define("LOGIC_MODE", 'normal'); }


// Event Display Modes
	$defaults = getEventDefaults($_SESSION['eventID']); // Have to re-load as it could change with POST
	$_SESSION['dataModes']['tournamentDisplay'] = $defaults['tournamentDisplay'];
	$_SESSION['dataModes']['tournamentSort'] = $defaults['tournamentSorting'];


// Match Colors
	if($_SESSION['tournamentID'] != null){
		$tournamentID = (int)$_SESSION['tournamentID'];
		$sql = "SELECT colorName, colorCode, contrastCode
				FROM eventTournaments, systemColors
				WHERE eventTournaments.tournamentID = {$tournamentID}
				AND color1ID = colorID";
		$result = mysqlQuery($sql, SINGLE);

		define("COLOR_NAME_1",$result['colorName']);
		define("COLOR_CODE_1",$result['colorCode']);
		define("COLOR_CONTRAST_CODE_1",$result['contrastCode']);

		$sql = "SELECT colorName, colorCode, contrastCode
				FROM eventTournaments, systemColors
				WHERE tournamentID = {$tournamentID}
				AND color2ID = colorID";
		$result = mysqlQuery($sql, SINGLE);

		define("COLOR_NAME_2",$result['colorName']);
		define("COLOR_CODE_2",$result['colorCode']);
		define("COLOR_CONTRAST_CODE_2",$result['contrastCode']);
	}

	if(!defined('COLOR_NAME_1')){ define("COLOR_NAME_1", null); }
	if(!defined('COLOR_NAME_2')){ define("COLOR_NAME_2", null); }
	if(!defined('COLOR_CODE_1')){ define("COLOR_CODE_1", null); }
	if(!defined('COLOR_CODE_2')){ define("COLOR_CODE_2", null); }
	if(!defined('COLOR_CONTRAST_CODE_1')){ define("COLOR_CONTRAST_CODE_1", '#000'); }
	if(!defined('COLOR_CONTRAST_CODE_2')){ define("COLOR_CONTRAST_CODE_2", '#000'); }



// FUNCTIONS ///////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////

/******************************************************************************/

function setPermissions(){
// Intialize the permissions constant with what the current user can and can't do.

	$permissionsList =
		['EVENT_VIDEO','EVENT_SCOREKEEP','EVENT_MANAGEMENT',
		'SOFTWARE_EVENT_SWITCHING','SOFTWARE_ASSIST','SOFTWARE_ADMIN',
		'STATS_EVENT','STATS_ALL',
		'VIEW_HIDDEN','VIEW_SETTINGS','VIEW_EMAIL'];

	foreach($permissionsList as $permisionType){
		$permissionsArray[$permisionType] = false;
	}
	$permissionsArray['VIEW_ROSTER']		= false;
	$permissionsArray['VIEW_SCHEDULE']		= false;
	$permissionsArray['VIEW_MATCHES'] 		= false;
	$permissionsArray['VIEW_RULES'] 		= false;

	switch($_SESSION['userName']){

		case 'eventOrganizer':

			$permissionsArray['EVENT_MANAGEMENT'] 	= true;
			$permissionsArray['STATS_EVENT'] 		= true;
			// Deliberate fall-thought

		case 'eventStaff':

			$permissionsArray['EVENT_SCOREKEEP'] 	= true;
			$permissionsArray['VIEW_ROSTER']		= true;
			$permissionsArray['VIEW_SCHEDULE']		= true;
			$permissionsArray['VIEW_MATCHES'] 		= true;
			$permissionsArray['VIEW_RULES'] 		= true;

		case '':

			if(isRosterPublished($_SESSION['eventID']) == true){
				$permissionsArray['VIEW_ROSTER'] = true;
			}

			if(isSchedulePublished($_SESSION['eventID']) == true){
				$permissionsArray['VIEW_SCHEDULE'] = true;
			}

			if(isMatchesPublished($_SESSION['eventID']) == true){
				$permissionsArray['VIEW_MATCHES'] = true;
			}

			if(isRulesPublished($_SESSION['eventID']) == true){
				$permissionsArray['VIEW_RULES'] = true;
			}

			// No user name, no permissions.
			break;
		default:
			$selectFields = '';
			foreach($permissionsList as $permisionType){
				if($selectFields != ''){
					$selectFields .= ", ";
				}
				$selectFields .= $permisionType;
			}

			$sql = "SELECT userID, {$selectFields}
					FROM systemUsers
					WHERE userName = '{$_SESSION['userName']}'";
			$permData = mysqlQuery($sql, SINGLE);

			$_SESSION['userID'] = (int)$permData['userID'];
			$eventID = (int)$_SESSION['eventID'];
			unset($permData['userID']);

			foreach($permData as $field => $bool){
				if($bool == true){
					$permissionsArray[$field] = true;
				}
			}

			if(isRosterPublished($_SESSION['eventID']) == true){
				$permissionsArray['VIEW_ROSTER'] = true;
			}

			if(isSchedulePublished($_SESSION['eventID']) == true){
				$permissionsArray['VIEW_SCHEDULE'] = true;
			}

			if(isMatchesPublished($_SESSION['eventID']) == true){
				$permissionsArray['VIEW_MATCHES'] = true;
			}

			if(isRulesPublished($_SESSION['eventID']) == true){
				$permissionsArray['VIEW_RULES'] = true;
			}

			if($permissionsArray['EVENT_MANAGEMENT'] == false){
				$sql = "SELECT 1
						FROM systemUserEvents
						WHERE userID = {$_SESSION['userID']}
						AND eventID = {$eventID}";
				$isAttached = (bool)mysqlQuery($sql, SINGLE);

				if($isAttached == true){
					$permissionsArray['EVENT_SCOREKEEP'] 	= true;
					$permissionsArray['EVENT_MANAGEMENT'] 	= true;
					$permissionsArray['STATS_EVENT'] 		= true;
				}
			}

			if($permissionsArray['STATS_ALL'] == true){
				$permissionsArray['STATS_EVENT'] = true;
			}

			if(    $permissionsArray['VIEW_HIDDEN'] == true
				|| $permissionsArray['VIEW_SETTINGS'] == true
				|| $permissionsArray['STATS_EVENT'] == true){

				$permissionsArray['VIEW_ROSTER']	= true;
				$permissionsArray['VIEW_SCHEDULE']	= true;
				$permissionsArray['VIEW_MATCHES']	= true;
				$permissionsArray['VIEW_RULES'] 	= true;
			}

	}

	define("ALLOW",$permissionsArray);

	if(ALLOW['SOFTWARE_ADMIN'] == false){
		unset($_SESSION['adminOptions']);
	}

/*
EVENT_VIDEO
	- Can add video links to fights
EVENT_SCOREKEEP
	- Can score matches/pieces.
	- Can advance fighters in brackets
	- Can finalize tournaments
EVENT_MANAGEMENT
	- Can add fighters to events/tournaments
	- Can add schools to the DB
	- Can create/populate pools & sets.
	- Can create brackets.

SOFTWARE_EVENT_SWITCHING
	- Can go between events without loging out
SOFTWARE_ASSIST
	- Can change software related settings, such as adding events or changing school names
	- Can reset event passwords
SOFTWARE_ADMIN
	- Can assign passwords to all users

STATS_EVENT
	- Can view statistics of the current event.
STATS_ALL
	- Can view agregate stats across multiple events.

VIEW_HIDDEN
	- Can see hidden events
VIEW_SETTINGS
	- Can view all the EVENT_MANAGEMENT functionality, but not change any settings.
VIEW_EMAIL
	- Can view event organizer e-mail addresses.
*/

}

/******************************************************************************/

function initializeSession(){
// Starts the session and initializes any session variables
// that are not set to null values.

	session_start();

	if(!isset($_SESSION['alertMessages'])){
		$_SESSION['alertMessages']['systemErrors'] = [];
		$_SESSION['alertMessages']['userErrors'] = [];
		$_SESSION['alertMessages']['userAlerts'] = [];
		$_SESSION['alertMessages']['userWarnings'] = [];
	}
	if(!isset($_SESSION['eventID'])){
		$_SESSION['eventID'] = 0;
	}
	if(!isset($_SESSION['isMetaEvent'])){
		$_SESSION['isMetaEvent'] = false;
	}
	if(!isset($_SESSION['tournamentID'])){
		$_SESSION['tournamentID'] = '';
	}
	if(!isset($_SESSION['matchID'])){
		$_SESSION['matchID'] = '';
	}
	if(!isset($_SESSION['groupSet']) || $_SESSION['groupSet'] == null){
		$_SESSION['groupSet'] = 1;
	}
	if(!isset($_SESSION['formatID']) || $_SESSION['formatID'] == null){
		$_SESSION['formatID'] = '';
	}
	if(!isset($_SESSION['userID'])){
		$_SESSION['userID'] = 0;
	}
	if(!isset($_SESSION['rulesID'])){
		$_SESSION['rulesID'] = 0;
	}

	if(!isset($_SESSION['userName'])){
		$_SESSION['userName'] = '';
	}
	if(!isset($_SESSION['rosterID'])){
		$_SESSION['rosterID'] = 0;
	}
	if(!isset($_SESSION['dayNum'])){
		$_SESSION['dayNum'] = 1;
	}

	if(!isset($_SESSION['alertMessages']['systemErrors'])){
		$_SESSION['alertMessages']['systemErrors'] = [];
	}
	if(!isset($_SESSION['alertMessages']['userErrors'])){
		$_SESSION['alertMessages']['userErrors'] = [];
	}
	if(!isset($_SESSION['alertMessages']['userAlerts'])){
		$_SESSION['alertMessages']['userAlerts'] = [];
	}
	if(!isset($_SESSION['alertMessages']['userWarnings'])){
		$_SESSION['alertMessages']['userAlerts'] = [];
	}

	if(!isset($_SESSION['rosterViewMode'])){
		$_SESSION['rosterViewMode'] = [];
	}
	if(!isset($_SESSION['ratingViewMode'])){
		$_SESSION['ratingViewMode'] = [];
	}
	if(!isset($_SESSION['viewMode']['time24hr'])){
		$_SESSION['viewMode']['time24hr'] = false;
	}
	if(!isset($_SESSION['displayByPool'])){
		$_SESSION['displayByPool'] = false;
	}
	if(!isset($_SESSION['bracketHelper'])){
		$_SESSION['bracketHelper'] = [];
	}

	if(!isset($_SESSION['dataModes']['tournamentDisplay'])){
		$_SESSION['dataModes']['tournamentDisplay'] = '';
	}
	if(!isset($_SESSION['dataModes']['tournamentSort'])){
		$_SESSION['dataModes']['tournamentSort'] = '';
	}
	if(!isset($_SESSION['dataModes']['percent'])){
		$_SESSION['dataModes']['percent'] = true;
	}
	if(!isset($_SESSION['dataModes']['extendedExchangeInfo'])){
		$_SESSION['dataModes']['extendedExchangeInfo'] = false;
	}

	if(!isset($_SESSION['filterForSystemRosterID'])){
		$_SESSION['filterForSystemRosterID'] = 0;
	}
	if(!isset($_SESSION['filters']['school'])){
		$_SESSION['filters']['school'] = false;
	}
	if(!isset($_SESSION['filters']['roster'])){
		$_SESSION['filters']['roster'] = false;
	}

}

/******************************************************************************/


// END OF FILE /////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////


