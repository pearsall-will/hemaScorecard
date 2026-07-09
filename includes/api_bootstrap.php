<?php
/*******************************************************************************
	Read-Only API Bootstrap

	Headless alternative to config.php: gets a DB connection and the
	DB_read_functions.php library loaded WITHOUT session init, permissions
	(ALLOW), or POST processing. Mirrors the minimal-boot pattern already
	used by healthcheck.php.

	Must be required before any output is produced — it installs an output
	buffer and a shutdown handler so that any fatal error (including
	checkMySQL()'s echo+die on a DB error) is converted into a clean JSON
	500 instead of leaking HTML into the response.

*******************************************************************************/

// Response helpers (apiRespond/apiError/apiShutdownHandler) — loaded first.
// This file has no dependencies of its own, so apiShutdownHandler exists
// before we register it below (register_shutdown_function() requires the
// callback to already be defined, or it silently fails to register).
	require_once(__DIR__.'/api/response.php');

// JSON error trap — installed next, before anything else can echo/die.
	ob_start();
	mysqli_report(MYSQLI_REPORT_OFF);
	$GLOBALS['__api_completed'] = false;
	register_shutdown_function('apiShutdownHandler');

// BASE_URL — this file lives in includes/, so the repo root is one level up.
// Defined before constants.php so its own BASE_URL guard leaves this value alone.
	if(!defined('BASE_URL')){
		define('BASE_URL', __DIR__.'/../');
	}

// Constants (deployment enums, DB connection constants via database.php,
// query-type codes, FORMAT_*, OPTION, etc.) — side-effect-free, safe headless.
	require_once(__DIR__.'/constants.php');

// Seed a minimal $_SESSION so setAlert() and other session-fallback reads
// don't warn. No session_start() — this endpoint is stateless.
	if(!isset($_SESSION) || !is_array($_SESSION)){
		$_SESSION = [];
	}
	$_SESSION['alertMessages'] = [
		'systemErrors'  => [],
		'userErrors'    => [],
		'userAlerts'    => [],
		'userWarnings'  => [],
	];
	$_SESSION['eventID']      = 0;
	$_SESSION['tournamentID'] = 0;
	$_SESSION['matchID']      = 0;
	$_SESSION['groupSet']     = 1;
	$_SESSION['dataModes']['tournamentSort'] = ''; // getEventTournaments() default sort

// ALLOW — read functions reference ALLOW['...']; the public API never has
// elevated permissions, so every flag is false.
	if(!defined('ALLOW')){
		define('ALLOW', [
			'EVENT_VIDEO'               => false,
			'EVENT_SCOREKEEP'           => false,
			'EVENT_MANAGEMENT'          => false,
			'SOFTWARE_EVENT_SWITCHING'  => false,
			'SOFTWARE_ASSIST'           => false,
			'SOFTWARE_ADMIN'            => false,
			'STATS_EVENT'               => false,
			'STATS_ALL'                 => false,
			'VIEW_HIDDEN'               => false,
			'VIEW_SETTINGS'             => false,
			'VIEW_EMAIL'                => false,
			'VIEW_ROSTER'               => false,
			'VIEW_SCHEDULE'             => false,
			'VIEW_MATCHES'              => false,
			'VIEW_RULES'                => false,
		]);
	}

// NAME_MODE / IS_TEAMS — config.php derives these per-tournament; only
// affects sort order and a couple of roster labels, so fixed defaults are
// acceptable for a read API.
	if(!defined('NAME_MODE'))   { define('NAME_MODE', 'firstName'); }
	if(!defined('NAME_MODE_2')) { define('NAME_MODE_2', 'lastName'); }
	if(!defined('IS_TEAMS'))    { define('IS_TEAMS', false); }

// Function libraries (read/write/display/data/mysql/general/POST/scoring/
// cutting/stats). Output-free — write/display/POST functions are only
// defined here, never called.
	require_once(BASE_URL.'includes/function_lib.php');

// Connect. Sets $GLOBALS['___mysqli_ston'].
	connectToDB();

// END OF FILE /////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
