<?php
/*******************************************************************************
	htmx snippet for adminTournaments.php

	Inline validation for a custom ranking formula input. Receives one
	tier's updateTournament[customCriteria][N][formula] (and [fallback])
	via hx-include and echoes a validity fragment for the row's message
	target. Server side validation on save remains authoritative.

*******************************************************************************/

define('BASE_URL' , $_SERVER['DOCUMENT_ROOT'].'/');
include_once(BASE_URL.'includes/config.php');

if(ALLOW['EVENT_MANAGEMENT'] == false){
	exit;
}

$postedCriteria = @$_REQUEST['updateTournament']['customCriteria'];
if(is_array($postedCriteria) == false){
	exit;
}

// hx-include is scoped to a single row, so only one tier is present
foreach($postedCriteria as $tier){

	$source = trim((string)@$tier['formula']);
	if($source === ''){
		exit;
	}

	$fallback = trim((string)@$tier['fallback']);
	if($fallback === ''){
		$fallback = '0';
	}

	$compiled = formula_compile($source, $fallback, customRankingFormulaFields());

	if(isset($compiled['error'])){
		// Compiler error strings are already html-escaped where they echo
		// user input, but escape the whole message as defense in depth.
		$message = htmlspecialchars($compiled['error'], ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
		echo "<span class='form-error is-visible' style='display:block;'>{$message}</span>";
	} else {
		echo "<span style='color:green;'>&#10003; Valid formula</span>";
	}
	exit;
}
