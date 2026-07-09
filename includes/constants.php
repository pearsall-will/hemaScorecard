<?php
/*******************************************************************************
	System & Program Constants

	Extracted from config.php so it can be required headlessly (no session,
	no DB connection, no permissions, no POST processing) — e.g. by the
	read-only API bootstrap. Side-effect-free: only defines constants/$options
	and includes database.php for the connection constants.

*******************************************************************************/

	define("DEBUGGING", 0);
	date_default_timezone_set("UTC");

	define("DEPLOYMENT_UNKNOWN",0);
	define("DEPLOYMENT_PRODUCTION",1);
	define("DEPLOYMENT_TEST",2);
	define("DEPLOYMENT_LOCAL",3);

// Database Connection
	if(!defined('BASE_URL')){
		define('BASE_URL' , $_SERVER['DOCUMENT_ROOT'].'/');
	}
	include(BASE_URL.'includes/database.php');

	if(!defined('DEPLOYMENT')){
		define('DEPLOYMENT' , DEPLOYMENT_UNKNOWN);
	}

// Program Related Constants

	// User Types
	define("NO_LOGIN",0);

	// Alert Codes
	define("SYSTEM",1);
	define("USER_ERROR",2);
	define("USER_ALERT",3);
	define("USER_WARNING",4);

	// mysqlQuery() function codes
	define("SEND",0);
	define("INDEX",1);
	define("RAW",2);
	define("NUM_ROWS",3);
	define("ASSOC",4);
	define("SINGLE",5);
	define("KEY",6);
	define("KEY_SINGLES",7);
	define("SINGLES",8);

	define("SQL_FALSE",0);
	define("SQL_TRUE",1);

	define("DEFAULT_EVENT",1);
	define("DEFAULT_TOURNAMENT_ID",0);
	define("TEST_EVENT_ID",2);

	define("FINALS","0");
	define("ALL_GROUP_SETS",0);

	define("EXPORT_DIR",'exports/');

	define('FIRST_YEAR', 2015);

// Tournament Related Constants

	define("DEFAULT_COLOR_NAME_1",'RED');
	define("DEFAULT_COLOR_CODE_1",'#F66');
	define("DEFAULT_COLOR_NAME_2",'BLUE');
	define("DEFAULT_COLOR_CODE_2",'#66F');

	define("DEFAULT_MAX_DOUBLES",3);
	define("POOL_SIZE_LIMIT",30);	// If you raise this you also need to add the match order to the table.
	define("STAFF_COMPETENCY_MAX",9);

	define("PENALTY_CARD_NONE",		null);
	define("PENALTY_CARD_YELLOW",	34);
	define("PENALTY_CARD_RED",		35);
	define("PENALTY_CARD_BLACK",	38);

	// The types of tournaments
	define("FORMAT_NONE",0);
	define("FORMAT_RESULTS",1);
	define("FORMAT_MATCH",2);
	define("FORMAT_SOLO",3);
	define("FORMAT_META",4);

	// Sentinel tournamentRankingID for a tournament defined ranking instead of a systemRankings template
	define("RANKING_CUSTOM",-1);

	define("NO_AFTERBLOW",1);
	define("DEDUCTIVE_AFTERBLOW",2);
	define("FULL_AFTERBLOW",3);

	define("REVERSE_SCORE_NO",0);
	define("REVERSE_SCORE_GOLF",1);
	define("REVERSE_SCORE_INJURY",2);

	define("ATTACK_CONTROL_DB",9);
	define("ATTACK_AFTERBLOW_DB",13);
	define("TARGET_SHALLOW_DB",33);
	define("PREFIX_SHALLOW_DB",101);

	define("SUB_MATCH_ANALOG",0);
	define("SUB_MATCH_DIGITAL",1);

	$num2atk[1] = 'refPrefix';
	$num2atk[2] = 'refTarget';
	$num2atk[3] = 'refType';
	define("NUM_2_ATK", $num2atk);

// Bracket Constants

	define("BRACKET_PRIMARY",1);
	define("BRACKET_SECONDARY",2);

	define("ELIM_TYPE_SINGLE",1);
	define("ELIM_TYPE_CONSOLATION",2);
	define("ELIM_TYPE_LOWER_BRACKET",3);
	define("ELIM_TYPE_TRUE_DOUBLE",4);

// Display Related Constants

	define("EVENT_ACTIVE_LIMIT",6);
	define("EVENT_UPCOMING_LIMIT",1);
	define("DATA_SERIES_MAX",4);

// Logistics Constants

	define("STAFF_CHECK_IN_NONE",0);
	define("STAFF_CHECK_IN_ALLOWED",1);
	define("STAFF_CHECK_IN_MANDATORY",2);

	define("SCHEDULE_BLOCK_TOURNAMENT",1);
	define("SCHEDULE_BLOCK_WORKSHOP",2);
	define("SCHEDULE_BLOCK_STAFFING",3);
	define("SCHEDULE_BLOCK_MISC",4);

	define("SCHEDULE_COLOR_TOURNAMENT",'#1779ba');
	define("SCHEDULE_COLOR_WORKSHOP","#3adb76");
	define("SCHEDULE_COLOR_STAFFING","#ffae00");
	define("SCHEDULE_COLOR_MISC","#BF5FFF");
	define("SCHEDULE_COLOR_CONFLICT","#cc4b37");

	define("LOGISTICS_ROLE_DIRECTOR",1);
	define("LOGISTICS_ROLE_JUDGE",2);
	define("LOGISTICS_ROLE_TABLE",3);
	define("LOGISTICS_ROLE_UNKONWN",4);
	define("LOGISTICS_ROLE_INSTRUCTOR",5);
	define("LOGISTICS_ROLE_GENERAL",6);
	define("LOGISTICS_ROLE_PARTICIPANT",7);

	define("STAFF_CONFLICTS_NO",0);     // Don't check staff conflicts
	define("STAFF_CONFLICTS_HARD",100); // Limit everything

// Video Constants
	define("VIDEO_SOURCE_UNKNOWN",0);
	define("VIDEO_SOURCE_YOUTUBE",1);
	define("VIDEO_SOURCE_NONE",2);
	define("VIDEO_SOURCE_GOOGLE_DRIVE",3);

	define("VIDEO_STREAM_UNKNOWN",0);
	define("VIDEO_STREAM_MATCH",1);
	define("VIDEO_STREAM_LOCATION",2);
	define("VIDEO_STREAM_VIRTUAL",3);


// Event Overal Rating

	define('EVENT_RATING_MIN_RATING',800);

	define('GLICKO_CONSTANT', 173.7178);
	define('ESTIMATE_DEVIATION_M', -.1278);
	define('ESTIMATE_DEVIATION_B', 294);

	define('PROBABILITY_THRESHOLD',0.25);
	define('RATING_STEP',1);


// Options Defines

	// Match Options
	$options['M']['NUM_SUB_MATCHES'] 	= 2;
	$options['M']['SWAP_FIGHTERS'] 		= 3;

	// Tournament Options
	$options['T']["META_ROSTER_MODE"]				= 1;
		define("META_ROSTER_MODE_INCLUSIVE",0);
		define("META_ROSTER_MODE_EXCLUSIVE",1);
		define("META_ROSTER_MODE_EXTENDED",	2);
	$options['T']['ATTACK_DISPLAY_MODE'] 			= 4;
		define("ATTACK_DISPLAY_MODE_NORMAL",0);
		define("ATTACK_DISPLAY_MODE_GRID",	1);
		define("ATTACK_DISPLAY_MODE_CHECK",	2);
	$options['T']['AFTERBLOW_POINT_VALUE'] 			= 5;
	$options['T']['MATCH_TIE_MODE'] 				= 6;
		define("MATCH_TIE_MODE_NONE",	0);
		define("MATCH_TIE_MODE_EQUAL",	1);
		define("MATCH_TIE_MODE_UNEQUAL",2);
	$options['T']['TEAM_SWITCH_POINTS'] 			= 7;
	$options['T']['DOUBLES_ARE_NOT_SCORING_EXCH'] 	= 8;
	$options['T']['CONTROL_POINT_VALUE'] 	      	= 9;
	$options['T']["TEAM_SIZE"] 						= 10;
	$options['T']["DOUBLES_CARRY_FORWARD"] 			= 11;
	$options['T']["SUPPRESS_DIRECT_ENTRY"] 			= 12;
	$options['T']["PRIORITY_NOTICE_ON_NON_SCORING"] = 15;
	$options['T']["DENOTE_FIGHTERS_WITH_OPTION_CHECK"] = 16;
	$options['T']["MATCH_SOFT_CLOCK_TIME"] 		    = 17;
	$options['T']["PENALTY_ESCALATION_MODE"] 		= 18;
		define("PENALTY_ESCALATION_MODE_NONE",			0);
		define("PENALTY_ESCALATION_MODE_THREE_STRIKES",	1);
	$options['T']["TEAM_SWITCH_MODE"] 				= 19;
		define("TEAM_SWITCH_MODE_RELAY",	0);
		define("TEAM_SWITCH_MODE_MOF",		1);
	$options['T']["MATCH_ORDER_MODE"] 				= 20;
		define("MATCH_ORDER_MODE_DEFAULT",  0);
		define("MATCH_ORDER_MODE_ORIGINAL",	1);
	$options['T']["SUPPRESS_MATCH_SCORE_OVERSHOOT"] = 21;
	$options['T']["BRACKET_POINT_CAP"]              = 22;
	$options['T']["FINALS_POINT_CAP"]               = 23;
	$options['T']["DEDUCTION_ADDITION_MODE"]        = 25;
		define("DEDUCTION_ADDITION_MODE_ADD", 0);
		define("DEDUCTION_ADDITION_MODE_MAX", 1);
		define("DEDUCTION_ADDITION_MODE_RMS", 2);
	$options['T']["PENALTIES_ADD_POINTS"]           = 26;
	$options['T']["LIMIT_SHALLOW"]                  = 27;
	$options['T']["MINIMUM_EXCH_TIME"]              = 28;
	$options['T']["POINT_SPREAD_START_VAL"]         = 29;
	$options['T']["BONUS_POINT_NAME"]               = 31;
		define("BONUS_POINT_NAME_CONTROL", 0);
		define("BONUS_POINT_NAME_BOUND", 1);

	// Event Options
	$options['E']["PENALTY_ACTION_IS_MANDATORY"]	= 13;
	$options['E']["HIDE_WHITE_CARD_PENALTIES"] 		= 14;
	$options['E']["SHOW_FIGHTER_RATINGS"] 		    = 24;
	$options['E']["USE_PARTICIPANT_IDS"] 		    = 30;
		define("PARTICIPANT_IDS_NO",0);
		define("PARTICIPANT_IDS_APPEND",1);
		define("PARTICIPANT_IDS_REPLACE",2);

	define('OPTION',$options);

// END OF FILE /////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
