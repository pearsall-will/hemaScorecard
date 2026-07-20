<?php
/*******************************************************************************
	htmx fragment for scoreMatchDisplayOBS.php

	Polled ~once per second by the OBS display page. Renders ONLY the scorebug
	markup (no <html>/<head>) so htmx can swap it into #obs-scorebug in place,
	giving flash-free live updates.

	Public / read-only: no permission check (the parent page is a public overlay).
	Reuses the existing data layer (getMatchInfo, getCombatantName,
	getFighterMatchPenalties, getMatchDoubles, …); the render helpers in
	scoreMatchDisplay.php are private to that file, so the (leaner) markup here
	is authored fresh but targets the classes defined in the parent's <style>.
*******************************************************************************/

define('BASE_URL', $_SERVER['DOCUMENT_ROOT'].'/');
include_once(BASE_URL.'includes/config.php');

$matchID = (int)@$_REQUEST['m'];
if($matchID == 0){
	echo "<div class='obs-endtext'>No match selected</div>";
	exit;
}

$matchInfo = getMatchInfo($matchID);
if(empty($matchInfo) || (int)@$matchInfo['matchID'] == 0){
	echo "<div class='obs-endtext'>Match not found</div>";
	exit;
}

$tournamentID = (int)$matchInfo['tournamentID'];

// --- Gather display data (mirrors scoreMatchDisplay.php's helpers) -----------
$fighter1Name = getCombatantName($matchInfo['fighter1ID']);
$fighter2Name = getCombatantName($matchInfo['fighter2ID']);

$fighter1Score = ($matchInfo['fighter1score'] === null) ? '/' : $matchInfo['fighter1score'];
$fighter2Score = ($matchInfo['fighter2score'] === null) ? '/' : $matchInfo['fighter2score'];

// Schools are suppressed for team tournaments (the "name" is already the team).
$showSchools    = (isTeamLogic($tournamentID) == false);
$fighter1School = $showSchools ? (string)@$matchInfo['fighter1School'] : '';
$fighter2School = $showSchools ? (string)@$matchInfo['fighter2School'] : '';

// Winner emphasis.
$winnerID = (int)@$matchInfo['winnerID'];
$f1Wins   = ($winnerID != 0 && $winnerID == (int)$matchInfo['fighter1ID']);
$f2Wins   = ($winnerID != 0 && $winnerID == (int)$matchInfo['fighter2ID']);

// Colour names for the "Winner: <colour>" centre text.
$colorNames  = getTournamentColors($tournamentID);
$color1Name  = @$colorNames[1];
$color2Name  = @$colorNames[2];

// --- Match time, formatted server-side as M:SS -------------------------------
// (There is no PHP time-formatting helper; the app formats time in JS only.)
function obs_formatTime($seconds){
	$seconds = (int)round($seconds);
	$sign    = ($seconds < 0) ? '-' : '';
	$seconds = abs($seconds);
	return $sign.intdiv($seconds, 60).':'.str_pad($seconds % 60, 2, '0', STR_PAD_LEFT);
}

$timeValue = (int)@$matchInfo['matchTime'];
if(isTimerCountdown($tournamentID) == true){
	// Count-down mode: show remaining time, clamped so it never goes negative
	// (the original display hides negative time).
	$timeValue = max(0, (int)@$matchInfo['timeLimit'] - $timeValue);
}
$timeDisplay = obs_formatTime($timeValue);

// --- End-of-match centre state -----------------------------------------------
// endType comes from getMatchInfo(): the terminal exchange type when complete
// ('winner' / 'doubleOut' / 'tie'), or 'ignore', or null while still fighting.
$endType   = @$matchInfo['endType'];
$endText1  = '';
$endText2  = '';
switch($endType){
	case 'winner':
		$endText1 = 'Winner';
		if($f1Wins){ $endText2 = $color1Name; }
		elseif($f2Wins){ $endText2 = $color2Name; }
		break;
	case 'tie':
		$endText2 = 'DRAW';
		break;
	case 'doubleOut':
		$endText1 = 'DOUBLE OUT';
		$endText2 = 'No Winner';
		break;
	case 'ignore':
		$endText1 = 'Incomplete';
		$endText2 = 'No Winner';
		break;
}
$matchOver = ($endText1 !== '' || $endText2 !== '');

// --- Doubles indicator -------------------------------------------------------
$showDoubles  = (isDoubleHits($tournamentID) == true);
$doublesCount = $showDoubles ? (int)getMatchDoubles($matchID) : 0;
$isDoubleOut  = ($endType == 'doubleOut');
$doublesText  = $doublesCount.' Double'.($doublesCount == 1 ? '' : 's');
if($doublesCount == 0){ $doublesText .= ' :)'; }
elseif($doublesCount >= 2){ $doublesText .= ' :('; }

// --- Penalty cards -----------------------------------------------------------
$penaltyClassMap = [
	'yellowCard' => 'penalty-card-yellow',
	'redCard'    => 'penalty-card-red',
	'blackCard'  => 'penalty-card-black',
];
function obs_renderPenalties($penaltyList, $sideClass, $penaltyClassMap){
	echo "<div class='obs-penalties {$sideClass}'>";
	foreach((array)$penaltyList as $p){
		if(isset($penaltyClassMap[$p['card']]) == false){ continue; }
		$cls = $penaltyClassMap[$p['card']];
		echo "<span class='obs-penalty {$cls}'>".htmlspecialchars($p['name'])."</span>";
	}
	echo "</div>";
}
$penalties1 = getFighterMatchPenalties($matchID, 1);
$penalties2 = getFighterMatchPenalties($matchID, 2);

// Helper: winner/loser emphasis class for a given side.
$f1Emphasis = $f1Wins ? 'is-winner' : ($f2Wins ? 'is-loser' : '');
$f2Emphasis = $f2Wins ? 'is-winner' : ($f1Wins ? 'is-loser' : '');
?>

<!-- Names -->
<div class="obs-row obs-names">
	<div class="obs-name f1 <?= $f1Emphasis ?>"><?= htmlspecialchars($fighter1Name) ?></div>
	<div class="obs-name f2 <?= $f2Emphasis ?>"><?= htmlspecialchars($fighter2Name) ?></div>
</div>

<!-- Schools / teams -->
<div class="obs-row obs-schools">
	<div class="obs-school f1"><?= htmlspecialchars($fighter1School) ?></div>
	<div class="obs-school f2"><?= htmlspecialchars($fighter2School) ?></div>
</div>

<!-- Scores + centre info -->
<div class="obs-scores">
	<div class="obs-score f1 <?= $f1Emphasis ?>"><?= htmlspecialchars((string)$fighter1Score) ?></div>

	<div class="obs-center">
		<?php if($matchOver): ?>
			<div class="obs-endtext"><?= htmlspecialchars($endText1) ?></div>
			<?php if($endText2 !== ''): ?>
				<div class="obs-endtext"><b><?= htmlspecialchars($endText2) ?></b></div>
			<?php endif ?>
		<?php else: ?>
			<div class="obs-timer"><?= $timeDisplay ?></div>
		<?php endif ?>

		<?php if($showDoubles): ?>
			<div class="obs-doubles <?= $isDoubleOut ? 'is-doubleout' : '' ?>">
				<?= htmlspecialchars($doublesText) ?>
			</div>
		<?php endif ?>
	</div>

	<div class="obs-score f2 <?= $f2Emphasis ?>"><?= htmlspecialchars((string)$fighter2Score) ?></div>
</div>

<!-- Penalty cards -->
<div class="obs-row">
	<?php obs_renderPenalties($penalties1, 'f1', $penaltyClassMap); ?>
	<?php obs_renderPenalties($penalties2, 'f2', $penaltyClassMap); ?>
</div>
