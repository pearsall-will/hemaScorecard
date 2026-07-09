<?php
/*******************************************************************************
	API Response Helpers

	Every route handler must end by calling apiRespond() or apiError() —
	both set $GLOBALS['__api_completed'] = true and exit. If execution ends
	any other way (an uncaught fatal, or checkMySQL()'s echo+die on a DB
	error), apiShutdownHandler() notices the sentinel is still false,
	discards whatever HTML/text was buffered, and emits a JSON 500 instead.

*******************************************************************************/

function apiShutdownHandler(){
	if($GLOBALS['__api_completed'] === true){
		return; // A handler already emitted a clean response.
	}

	if(ob_get_length() !== false){
		ob_end_clean(); // Discard buffered output (e.g. checkMySQL's "<BR>***Error:").
	}

	if(!headers_sent()){
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(500);
	}

	$fatal = error_get_last();
	echo json_encode([
		'error' => [
			'code'    => 'internal_error',
			'message' => $fatal ? 'Internal error' : 'Database or server error',
		],
	]);
}

function apiRespond($data, int $status = 200){
	$GLOBALS['__api_completed'] = true;

	if(ob_get_length() !== false){
		ob_end_clean();
	}
	if(!headers_sent()){
		header('Content-Type: application/json; charset=utf-8');
		http_response_code($status);
	}

	echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function apiError(int $status, string $code, string $message, array $extraHeaders = []){
	$GLOBALS['__api_completed'] = true;

	if(ob_get_length() !== false){
		ob_end_clean();
	}
	if(!headers_sent()){
		foreach($extraHeaders as $name => $value){
			header("{$name}: {$value}");
		}
		header('Content-Type: application/json; charset=utf-8');
		http_response_code($status);
	}

	echo json_encode(['error' => ['code' => $code, 'message' => $message]]);
	exit;
}

/******************************************************************************/

function apiQueryInt(string $key, int $default){
// Reads an integer query-string param. Returns the default if absent;
// 400s the request if present but not a valid non-negative integer.

	if(!isset($_GET[$key]) || $_GET[$key] === ''){
		return $default;
	}
	if(!ctype_digit((string)$_GET[$key])){
		apiError(400, 'bad_request', "Query parameter '{$key}' must be a non-negative integer");
	}
	return (int)$_GET[$key];
}

function apiQueryEnum(string $key, array $allowed, $default){
// Reads a query-string param constrained to a whitelist of values.
// 400s the request if present but not in $allowed.

	if(!isset($_GET[$key]) || $_GET[$key] === ''){
		return $default;
	}
	if(!in_array($_GET[$key], $allowed, true)){
		$allowedStr = implode(', ', $allowed);
		apiError(400, 'bad_request', "Query parameter '{$key}' must be one of: {$allowedStr}");
	}
	return $_GET[$key];
}

// END OF FILE /////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
