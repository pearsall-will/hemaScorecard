<?php
/*******************************************************************************
	Unit tests for includes/functions/formula_functions.php

	Dependency free CLI test script:
		php tests/php/formula_functions.test.php
	or in the container:
		docker-compose exec web php /hemaScorecard/tests/php/formula_functions.test.php

	Exits non-zero on any failure.

*******************************************************************************/

require_once __DIR__ . '/../../includes/functions/formula_functions.php';

$passCount = 0;
$failCount = 0;

function check($name, $condition, $detail = ''){
	global $passCount, $failCount;
	if($condition){
		$passCount++;
	} else {
		$failCount++;
		echo "FAIL: {$name}";
		if($detail !== ''){
			echo " -- {$detail}";
		}
		echo "\n";
	}
}

function compiles($source, $fallback = '0', $alias = ''){
	global $whitelist;
	return formula_compile($source, $fallback, $whitelist, $alias);
}

function assertSql($name, $source, $expectedSql, $fallback = '0', $alias = ''){
	$result = compiles($source, $fallback, $alias);
	if(isset($result['error'])){
		check($name, false, "unexpected error: {$result['error']}");
		return;
	}
	check($name, $result['sql'] === $expectedSql, "got: {$result['sql']}");
}

function assertRejected($name, $source, $fallback = '0'){
	$result = compiles($source, $fallback);
	check($name, isset($result['error']) && isset($result['sql']) == false,
		isset($result['sql']) ? "compiled to: {$result['sql']}" : '');
}

$whitelist = [
	'wins'          => 'Wins',
	'matches'       => 'Matches',
	'pointsFor'     => 'Points For',
	'pointsAgainst' => 'Points Against',
	'doubles'       => 'Doubles',
	'AbsPointsFor'  => 'Absolute Points For',
];

/*******************************************************************************
	Precedence and associativity
*******************************************************************************/

assertSql('multiplication binds tighter than addition',
	'1 + 2 * wins',
	'(1 + (2 * wins))');

assertSql('subtraction is left associative',
	'wins - 4 - 3',
	'((wins - 4) - 3)');

assertSql('division is left associative',
	'wins / 4 / 2',
	'IFNULL(IFNULL(wins / NULLIF(4, 0), 0) / NULLIF(2, 0), 0)');

assertSql('parentheses override precedence',
	'(1 + wins) * 3',
	'((1 + wins) * 3)');

assertSql('decimal literals pass through',
	'wins * 0.5',
	'(wins * 0.5)');

/*******************************************************************************
	Unary minus
*******************************************************************************/

assertSql('unary minus on a field', '-wins', '(-wins)');
assertSql('double unary minus', '--wins', '(-(-wins))');
assertSql('unary minus in a product', '2 * -wins', '(2 * (-wins))');

/*******************************************************************************
	Division guard
*******************************************************************************/

assertSql('division wrapped with fallback',
	'pointsFor / matches',
	'IFNULL(pointsFor / NULLIF(matches, 0), 9001)',
	'9001');

assertSql('nested divisions each wrapped',
	'pointsFor / (pointsAgainst / matches)',
	'IFNULL(pointsFor / NULLIF(IFNULL(pointsAgainst / NULLIF(matches, 0), 5), 0), 5)',
	'5');

assertSql('negative fallback accepted',
	'pointsFor / doubles',
	'IFNULL(pointsFor / NULLIF(doubles, 0), -1.5)',
	'-1.5');

$result = compiles('pointsFor / matches', '');
check('empty fallback defaults to 0',
	isset($result['sql']) && $result['sql'] === 'IFNULL(pointsFor / NULLIF(matches, 0), 0)',
	isset($result['sql']) ? "got: {$result['sql']}" : "error: {$result['error']}");

/*******************************************************************************
	Alias compilation
*******************************************************************************/

assertSql('alias prefixes fields, not literals',
	'(pointsFor - 2) / matches',
	'IFNULL((eS.pointsFor - 2) / NULLIF(eS.matches, 0), 0)',
	'0', 'eS.');

$result = compiles('pointsFor / matches', '0', 'eS.');
check('canonical is alias free',
	isset($result['canonical']) && $result['canonical'] === 'IFNULL(pointsFor / NULLIF(matches, 0), 0)',
	isset($result['canonical']) ? "got: {$result['canonical']}" : "error: {$result['error']}");

/*******************************************************************************
	Identifier canonicalization
*******************************************************************************/

assertSql('identifiers are case insensitive and canonicalized',
	'WINS + abspointsfor',
	'(wins + AbsPointsFor)');

$result = compiles('wins + matches / doubles');
check('fields list contains canonical names',
	isset($result['fields']) && $result['fields'] === ['wins', 'matches', 'doubles'],
	isset($result['fields']) ? 'got: '.implode(',', $result['fields']) : "error: {$result['error']}");

/*******************************************************************************
	Depth and size limits
*******************************************************************************/

assertSql('nesting to the depth limit compiles',
	str_repeat('(', 9).'wins'.str_repeat(')', 9),
	'wins');

assertRejected('nesting past the depth limit rejected',
	str_repeat('(', 15).'wins'.str_repeat(')', 15));

assertRejected('over-length source rejected',
	'wins + '.str_repeat('1 + ', 60).'1');

assertRejected('over max source characters rejected',
	str_repeat(' ', 201).'wins');

/*******************************************************************************
	Injection and malformed input rejections
*******************************************************************************/

assertRejected('stacked query', '1; DROP TABLE eventStandings');
assertRejected('paren breakout', 'wins) OR (1=1');
assertRejected('quote and comment', "wins'--");
assertRejected('backticks', '`wins`');
assertRejected('hash comment', 'wins # comment');
assertRejected('block comment', 'wins /* x */');
assertRejected('function call', 'sleep(1)');
assertRejected('non-whitelisted field', 'score + wins');
assertRejected('unknown identifier', 'winz + 1');
assertRejected('scientific notation splits to unknown ident', '1e9 + wins');
assertRejected('hex literal splits to unknown ident', '0x1F + wins');
assertRejected('empty formula', '');
assertRejected('whitespace only formula', '   ');
assertRejected('constant only formula', '1 + 2');
assertRejected('trailing operator', 'wins +');
assertRejected('leading binary operator', '* wins');
assertRejected('unbalanced open paren', '(wins + 1');
assertRejected('unbalanced close paren', 'wins + 1)');
assertRejected('adjacent values', 'wins matches');
assertRejected('comma', 'wins, matches');
assertRejected('bare decimal point', 'wins + .5');
assertRejected('trailing decimal point', 'wins + 5.');
assertRejected('bad fallback word', 'pointsFor / matches', 'DROP');
assertRejected('bad fallback expression', 'pointsFor / matches', '1+1');
assertRejected('bad fallback quote', 'pointsFor / matches', "9001'");

/*******************************************************************************
	Compiled output charset guard (fuzz over valid random formulas)
*******************************************************************************/

mt_srand(20260706);
$fields = array_keys($whitelist);
$allSafe = true;
for($i = 0; $i < 200; $i++){
	$parts = [];
	$numTerms = mt_rand(1, 6);
	for($t = 0; $t < $numTerms; $t++){
		if($t > 0){
			$ops = ['+','-','*','/'];
			$parts[] = $ops[mt_rand(0,3)];
		}
		if(mt_rand(0,2) == 0){
			$parts[] = (string)mt_rand(0, 100);
		} else {
			$parts[] = $fields[mt_rand(0, count($fields)-1)];
		}
	}
	// Guarantee at least one field reference
	$parts[] = '+';
	$parts[] = 'wins';

	$result = compiles(implode(' ', $parts), (string)mt_rand(-10, 9001));
	if(isset($result['error'])){
		$allSafe = false;
		echo "fuzz compile error on: ".implode(' ', $parts)." -- {$result['error']}\n";
		break;
	}
	if(preg_match('#^[A-Za-z0-9_+\-*/(), .]+$#', $result['sql']) != 1){
		$allSafe = false;
		echo "fuzz unsafe charset in: {$result['sql']}\n";
		break;
	}
}
check('fuzz: all valid formulas compile to safe charset', $allSafe);

/*******************************************************************************
	Result
*******************************************************************************/

echo "\n{$passCount} passed, {$failCount} failed\n";
exit($failCount > 0 ? 1 : 0);
