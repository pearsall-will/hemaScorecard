<?php
/*******************************************************************************
	scoreMatchDisplayOBS.php  —  OBS-optimized match display (POC)

	A framework-free, transparent-background version of scoreMatchDisplay.php,
	intended to be used as an OBS "Browser Source" overlay.

	Design goals (see plan i-want-to-do-staged-platypus.md):
	  - NO external CSS framework. All CSS lives in the single inline <style>
	    block below, heavily commented, so a broadcaster can override the look
	    by redefining the CSS custom properties (--f1-color, --obs-name-size, …)
	    or the clearly-named classes, without touching PHP.
	  - Transparent <body> so OBS composites the scorebug over a video feed.
	  - Public: reachable via ?m=<matchID> with no login (read-only display).
	  - Updates in place (no page reload / flash) via htmx polling of the
	    fragment endpoint scoreMatchDisplayOBS/htmx/scorebug.php.

	This is a "fake header" page (like scoreMatchDisplay.php / videoWatchWindow.php):
	it includes config.php for the DB + function library but deliberately does NOT
	include includes/header.php or includes/footer.php, which are what pull in
	Foundation + jQuery + the rest.
*******************************************************************************/

include_once('includes/config.php');

// Read the match id straight from the URL so the page is stateless: it does not
// depend on $_SESSION['matchID'] and therefore works on a logged-out OBS machine.
$matchID = (int)@$_GET['m'];

// Look up the tournament's real ring colours (per-tournament, not the global
// COLOR_CODE_* constants) so the overlay matches the fighters' assigned colours.
// Mirrors the query used by the streaming overlay in includes/functions/AJAX.php.
$f1Color = '#111111'; $f1Contrast = '#ffffff';
$f2Color = '#eeeeee'; $f2Contrast = '#000000';

if($matchID != 0){
	$matchInfo = getMatchInfo($matchID);
	$tournamentID = (int)@$matchInfo['tournamentID'];

	if($tournamentID != 0){
		$c1 = mysqlQuery("SELECT colorCode, contrastCode
							FROM eventTournaments, systemColors
							WHERE tournamentID = {$tournamentID} AND color1ID = colorID", SINGLE);
		$c2 = mysqlQuery("SELECT colorCode, contrastCode
							FROM eventTournaments, systemColors
							WHERE tournamentID = {$tournamentID} AND color2ID = colorID", SINGLE);
		if(!empty($c1['colorCode'])){ $f1Color = $c1['colorCode']; $f1Contrast = $c1['contrastCode']; }
		if(!empty($c2['colorCode'])){ $f2Color = $c2['colorCode']; $f2Contrast = $c2['contrastCode']; }
	}
}
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>HEMA Scorecard — OBS Match Display</title>

	<style>
	/* =========================================================================
	   OBS MATCH DISPLAY — inline stylesheet (no external CSS framework)

	   HOW TO CUSTOMISE
	   ----------------
	   Everything you are likely to change is a CSS custom property on :root
	   below. To override, add your own <style> AFTER this page loads (OBS lets
	   you inject "Custom CSS" on a browser source), e.g.:

	       :root { --obs-name-size: 4vw; --obs-bg: rgba(0,0,0,.4); }
	       .obs-name { text-transform: uppercase; }

	   The fighter colours (--f1-color etc.) are seeded from the tournament's
	   assigned ring colours in PHP above, but you can hard-override them too.
	   ========================================================================= */

	:root {
		/* Fighter 1 / Fighter 2 colours — seeded from the tournament's ring
		   colours. --*-contrast is the readable text colour on that background. */
		--f1-color:    <?= $f1Color ?>;
		--f1-contrast: <?= $f1Contrast ?>;
		--f2-color:    <?= $f2Color ?>;
		--f2-contrast: <?= $f2Contrast ?>;

		/* Page background. Transparent by default for OBS compositing.
		   Set to a colour (e.g. #000) to use the board as a full scene. */
		--obs-bg: transparent;

		/* --- SIZE & POSITION -------------------------------------------------
		   The overlay is a compact "scorebug" strip, NOT a full-screen board,
		   so most of the OBS canvas stays transparent for your camera/video.
		     - --obs-width / --obs-max-width control how wide the strip is.
		     - It is pinned to the BOTTOM by default (see .obs-board). To move it
		       to the top: .obs-board { top: 0; bottom: auto; }
		     - To make it fill the screen again (old behaviour): set
		       --obs-max-width: none and raise the font sizes below.            */
		--obs-width:     100%;      /* strip width */
		--obs-max-width: 900px;     /* centred bottom bar; use `none` for full width */

		/* Typographic scale (viewport units). Raise these for a bigger bug. */
		--obs-name-size:    1.9vw;
		--obs-school-size:  1.1vw;
		--obs-score-size:   6vh;
		--obs-timer-size:   4.5vh;
		--obs-center-size:  1.5vw;
		--obs-penalty-size: 1.1vw;

		/* Divider drawn between panels. */
		--obs-divider: 2px solid rgba(0,0,0,.85);
	}

	/* Reset just enough to lay out full-viewport without a framework. */
	* { box-sizing: border-box; margin: 0; padding: 0; }
	html, body { width: 100%; height: 100%; }
	body {
		background: var(--obs-bg);
		font-family: 'Chivo', 'Segoe UI', Arial, sans-serif;
		color: #fff;
		overflow: hidden;                 /* OBS sources should not scroll */
	}

	/* Compact scorebug pinned to the bottom of the OBS canvas. Its height is
	   driven by its content, so it only occupies a strip and the rest of the
	   canvas stays transparent. Move it to the top with:
	       .obs-board { top: 0; bottom: auto; }                                */
	.obs-board {
		position: fixed;
		left: 0; right: 0; bottom: 0;
		width: var(--obs-width);
		max-width: var(--obs-max-width);
		margin: 0 auto;                   /* centre the strip when max-width applies */
		display: flex;
		flex-direction: column;
	}

	/* App banner. Hidden by default to keep the bug compact — show it with
	   .obs-header { display: block }. */
	.obs-header {
		display: none;
		text-align: center;
		background: rgba(0,0,0,.85);
		font-size: 2.4vh;
		line-height: 1.3;
		padding: .2em 0;
	}

	/* Each horizontal band (names, schools, penalties) is a 2-up flex row. */
	.obs-row { display: flex; flex: 0 0 auto; }
	.obs-row > * { flex: 1 1 0; min-width: 0; }

	/* The score band is 3 columns: fighter1 | centre info | fighter2.
	   flex: 0 0 auto keeps it content-height (no vertical stretch). */
	.obs-scores { display: flex; flex: 0 0 auto; }
	.obs-scores > .obs-score  { flex: 2 1 0; }
	.obs-scores > .obs-center { flex: 1.4 1 0; }

	/* Fighter-coloured panels. .f1/.f2 map to the two ring colours. */
	.f1 { background: var(--f1-color); color: var(--f1-contrast); }
	.f2 { background: var(--f2-color); color: var(--f2-contrast); }

	/* Fighter name / school text. */
	.obs-name {
		font-size: var(--obs-name-size);
		line-height: 1.05;
		padding: .1em .3em;
		border-bottom: var(--obs-divider);
	}
	.obs-name.f1 { text-align: left;  border-right: var(--obs-divider); }
	.obs-name.f2 { text-align: right; border-left:  var(--obs-divider); }

	.obs-school {
		font-size: var(--obs-school-size);
		font-style: italic;
		padding: .1em .6em;
		border-bottom: var(--obs-divider);
		min-height: 1.6em;
	}
	.obs-school.f1 { text-align: left;  border-right: var(--obs-divider); }
	.obs-school.f2 { text-align: right; border-left:  var(--obs-divider); }

	/* Big numeric scores. */
	.obs-score {
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: var(--obs-score-size);
		line-height: 1;
	}
	.obs-score.f1 { border-right: var(--obs-divider); }
	.obs-score.f2 { border-left:  var(--obs-divider); }

	/* Centre column: timer while fighting, or the end-of-match state. */
	.obs-center {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		text-align: center;
		background: rgba(0,0,0,.85);
		color: #fff;
		padding: .1em .2em;
	}
	.obs-timer  { font-size: var(--obs-timer-size); line-height: 1; }
	.obs-endtext{ font-size: var(--obs-center-size); line-height: 1.15; }

	/* Winner / loser emphasis, toggled by classes emitted from the fragment. */
	.is-winner { font-weight: 700; }
	.is-loser  { opacity: .75; text-decoration: line-through; }

	/* Doubles indicator (e.g. "2 Doubles :("). */
	.obs-doubles { font-size: var(--obs-center-size); line-height: 1.2; }
	.obs-doubles.is-doubleout { color: #ff5a5a; font-weight: 700; }

	/* Penalty-card band. */
	.obs-penalties { min-height: 0; }
	.obs-penalties.f1 { text-align: left;  padding: .1em .4em; }
	.obs-penalties.f2 { text-align: right; padding: .1em .4em; }
	.obs-penalty {
		display: inline-block;
		font-size: var(--obs-penalty-size);
		padding: .05em .4em;
		margin: .1em .15em;
		border-radius: .15em;
		color: #000;
	}
	.penalty-card-yellow { background: #f2d600; }
	.penalty-card-red    { background: #e23b3b; color: #fff; }
	.penalty-card-black  { background: #111;    color: #fff; }

	/* Fallback message shown when no valid match is supplied. */
	.obs-placeholder {
		display: flex; align-items: center; justify-content: center;
		height: 100vh; color: #fff; font-size: 3vh; text-align: center;
		background: rgba(0,0,0,.6);
	}
	</style>
</head>

<body>
<?php if($matchID == 0): ?>

	<div class="obs-placeholder">
		Provide a match to display, e.g.
		<code>&nbsp;scoreMatchDisplayOBS.php?m=&lt;matchID&gt;</code>
	</div>

<?php else: ?>

	<div class="obs-board">

		<!-- Static chrome (does not reload). Hide via .obs-header{display:none}. -->
		<div class="obs-header">HEMA Scorecard</div>

		<!-- Dynamic scorebug: htmx polls the fragment every second and swaps
		     its markup in place — no full-page reload, so OBS never flashes. -->
		<div id="obs-scorebug"
			hx-get="scoreMatchDisplayOBS/htmx/scorebug.php?m=<?= $matchID ?>"
			hx-trigger="load, every 1s"
			hx-swap="innerHTML">
		</div>

	</div>

	<!-- Only dependency: htmx (same vendored file footer.php uses). No jQuery,
	     no Foundation, no external CSS. -->
	<script src="includes/scripts/vendor/htmx.js"></script>

<?php endif ?>
</body>
</html>
