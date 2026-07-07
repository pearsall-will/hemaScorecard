<?php
/*******************************************************************************
	Formula Functions

	Tokenizer, parser, and SQL compiler for user supplied custom ranking
	formulas. User text is never interpolated into SQL; the compiler
	regenerates a canonical SQL expression from the validated parse tree,
	so the output contains only whitelisted column names, numeric literals,
	arithmetic operators, parentheses, and IFNULL/NULLIF division guards.

	Every division is rewritten as
		IFNULL((num) / NULLIF((den), 0), fallback)
	so a divide by zero can never raise a strict-mode SQL error.

	No database or session dependencies; testable from the CLI.

*******************************************************************************/

define("FORMULA_MAX_LENGTH", 200);
define("FORMULA_MAX_TOKENS", 80);
define("FORMULA_MAX_DEPTH", 10);
define("FORMULA_MAX_COMPILED_LENGTH", 1000);

/******************************************************************************/

function formula_tokenize($source){
// Splits a formula into tokens: NUM, IDENT, OP (+ - * /), LPAREN, RPAREN.
// Any character outside the token grammar is a hard error, which rejects
// quotes, backticks, semicolons, and comment openers outright.
// Returns ['tokens' => [...]] or ['error' => user facing message].

	if(is_string($source) == false || trim($source) === ''){
		return ['error' => "Formula is empty."];
	}
	if(strlen($source) > FORMULA_MAX_LENGTH){
		return ['error' => "Formula is too long (max ".FORMULA_MAX_LENGTH." characters)."];
	}

	$tokens = [];
	$len = strlen($source);
	$i = 0;

	while($i < $len){
		$char = $source[$i];

		if(ctype_space($char)){
			$i++;
			continue;
		}

		if(ctype_digit($char)){
			$num = '';
			while($i < $len && ctype_digit($source[$i])){
				$num .= $source[$i];
				$i++;
			}
			if($i < $len && $source[$i] === '.'){
				if($i + 1 >= $len || ctype_digit($source[$i+1]) == false){
					return ['error' => "Number '{$num}.' must have digits after the decimal point."];
				}
				$num .= '.';
				$i++;
				while($i < $len && ctype_digit($source[$i])){
					$num .= $source[$i];
					$i++;
				}
			}
			$tokens[] = ['type' => 'NUM', 'value' => $num, 'pos' => $i - strlen($num)];

		} elseif(ctype_alpha($char)){
			$ident = '';
			$start = $i;
			while($i < $len && (ctype_alnum($source[$i]) || $source[$i] === '_')){
				$ident .= $source[$i];
				$i++;
			}
			$tokens[] = ['type' => 'IDENT', 'value' => $ident, 'pos' => $start];

		} elseif($char === '+' || $char === '-' || $char === '*' || $char === '/'){
			$tokens[] = ['type' => 'OP', 'value' => $char, 'pos' => $i];
			$i++;

		} elseif($char === '('){
			$tokens[] = ['type' => 'LPAREN', 'value' => '(', 'pos' => $i];
			$i++;

		} elseif($char === ')'){
			$tokens[] = ['type' => 'RPAREN', 'value' => ')', 'pos' => $i];
			$i++;

		} else {
			$safeChar = htmlspecialchars($char);
			return ['error' => "Unexpected character '{$safeChar}' at position ".($i+1)."."];
		}

		if(count($tokens) > FORMULA_MAX_TOKENS){
			return ['error' => "Formula is too complex (max ".FORMULA_MAX_TOKENS." tokens)."];
		}
	}

	return ['tokens' => $tokens];

}

/******************************************************************************/

function formula_parse($source){
// Recursive descent parser. Grammar (standard precedence):
//   expr    := term (('+' | '-') term)*
//   term    := factor (('*' | '/') factor)*
//   factor  := '-' factor | NUM | IDENT | '(' expr ')'
// Returns ['ast' => node] or ['error' => user facing message].
// AST nodes: ['num', value], ['field', name], ['neg', child],
//            ['op', operator, left, right]

	$result = formula_tokenize($source);
	if(isset($result['error'])){
		return $result;
	}
	$tokens = $result['tokens'];

	if(count($tokens) == 0){
		return ['error' => "Formula is empty."];
	}

	$state = ['tokens' => $tokens, 'index' => 0, 'depth' => 0];

	$ast = _formula_parseExpr($state);
	if(isset($ast['error'])){
		return $ast;
	}

	if($state['index'] < count($state['tokens'])){
		$token = $state['tokens'][$state['index']];
		$safeValue = htmlspecialchars($token['value']);
		return ['error' => "Unexpected '{$safeValue}' at position ".($token['pos']+1)."."];
	}

	return ['ast' => $ast];

}

/******************************************************************************/

function _formula_parseExpr(&$state){
// expr := term (('+' | '-') term)*

	$left = _formula_parseTerm($state);
	if(isset($left['error'])){
		return $left;
	}

	while(_formula_peek($state, 'OP', ['+','-'])){
		$op = $state['tokens'][$state['index']]['value'];
		$state['index']++;

		$right = _formula_parseTerm($state);
		if(isset($right['error'])){
			return $right;
		}
		$left = ['op', $op, $left, $right];
	}

	return $left;

}

/******************************************************************************/

function _formula_parseTerm(&$state){
// term := factor (('*' | '/') factor)*

	$left = _formula_parseFactor($state);
	if(isset($left['error'])){
		return $left;
	}

	while(_formula_peek($state, 'OP', ['*','/'])){
		$op = $state['tokens'][$state['index']]['value'];
		$state['index']++;

		$right = _formula_parseFactor($state);
		if(isset($right['error'])){
			return $right;
		}
		$left = ['op', $op, $left, $right];
	}

	return $left;

}

/******************************************************************************/

function _formula_parseFactor(&$state){
// factor := '-' factor | NUM | IDENT | '(' expr ')'

	$state['depth']++;
	if($state['depth'] > FORMULA_MAX_DEPTH){
		return ['error' => "Formula is nested too deeply (max ".FORMULA_MAX_DEPTH." levels)."];
	}

	if($state['index'] >= count($state['tokens'])){
		$state['depth']--;
		return ['error' => "Formula ends unexpectedly."];
	}

	$token = $state['tokens'][$state['index']];

	if($token['type'] === 'OP' && $token['value'] === '-'){
		$state['index']++;
		$child = _formula_parseFactor($state);
		if(isset($child['error'])){
			return $child;
		}
		$state['depth']--;
		return ['neg', $child];
	}

	if($token['type'] === 'NUM'){
		$state['index']++;
		$state['depth']--;
		return ['num', $token['value']];
	}

	if($token['type'] === 'IDENT'){
		$state['index']++;
		$state['depth']--;
		return ['field', $token['value']];
	}

	if($token['type'] === 'LPAREN'){
		$state['index']++;
		$inner = _formula_parseExpr($state);
		if(isset($inner['error'])){
			return $inner;
		}
		if(_formula_peek($state, 'RPAREN') == false){
			return ['error' => "Missing closing parenthesis."];
		}
		$state['index']++;
		$state['depth']--;
		return $inner;
	}

	$safeValue = htmlspecialchars($token['value']);
	return ['error' => "Unexpected '{$safeValue}' at position ".($token['pos']+1)."."];

}

/******************************************************************************/

function _formula_peek(&$state, $type, $values = null){
// True if the next token matches $type (and one of $values if given).

	if($state['index'] >= count($state['tokens'])){
		return false;
	}
	$token = $state['tokens'][$state['index']];
	if($token['type'] !== $type){
		return false;
	}
	if($values !== null && in_array($token['value'], $values, true) == false){
		return false;
	}
	return true;

}

/******************************************************************************/

function formula_compile($source, $fallback, $whitelist, $alias = ''){
// Compiles a formula into a safe SQL expression.
//   $source    - user formula text
//   $fallback  - numeric literal used when any division divides by zero
//   $whitelist - [columnName => display label]; identifier match is
//                case-insensitive and canonicalized to the array key
//   $alias     - table alias prefix for identifiers (e.g. 'eS.')
// Returns ['sql', 'canonical', 'fields'] or ['error' => message].
//   sql       - compiled expression with $alias applied
//   canonical - compiled expression with no alias (for duplicate checks)
//   fields    - unique canonical column names referenced

	$result = formula_parse($source);
	if(isset($result['error'])){
		return $result;
	}
	$ast = $result['ast'];

	if($fallback === null || $fallback === ''){
		$fallback = '0';
	}
	if(is_string($fallback) == false && is_numeric($fallback) == false){
		return ['error' => "Divide-by-zero fallback must be a number."];
	}
	$fallback = trim((string)$fallback);
	if(strlen($fallback) > 16 || preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $fallback) != 1){
		return ['error' => "Divide-by-zero fallback must be a number."];
	}

	// Case-insensitive identifier lookup, canonicalized to the whitelist key
	$lookup = [];
	foreach($whitelist as $column => $label){
		$lookup[strtolower($column)] = $column;
	}

	$fields = [];
	$check = _formula_validateFields($ast, $lookup, $whitelist, $fields);
	if($check !== true){
		return $check;
	}

	if(count($fields) == 0){
		return ['error' => "Formula must reference at least one field."];
	}

	$sql = _formula_emit($ast, $fallback, $alias);
	$canonical = ($alias === '') ? $sql : _formula_emit($ast, $fallback, '');

	if(strlen($sql) > FORMULA_MAX_COMPILED_LENGTH){
		return ['error' => "Formula is too long once compiled; please simplify it."];
	}

	return ['sql' => $sql, 'canonical' => $canonical, 'fields' => array_values($fields)];

}

/******************************************************************************/

function _formula_validateFields(&$node, $lookup, $whitelist, &$fields){
// Walks the AST, checks every identifier against the whitelist, and
// rewrites it in place to the canonical column name.
// Returns true or ['error' => message].

	switch($node[0]){
		case 'num':
			return true;

		case 'field':
			$key = strtolower($node[1]);
			if(isset($lookup[$key]) == false){
				$safeName = htmlspecialchars($node[1]);
				$validNames = implode(', ', array_keys($whitelist));
				return ['error' => "Unknown field '{$safeName}'. Valid fields are: {$validNames}."];
			}
			$node[1] = $lookup[$key];
			$fields[$node[1]] = $node[1];
			return true;

		case 'neg':
			return _formula_validateFields($node[1], $lookup, $whitelist, $fields);

		case 'op':
			$check = _formula_validateFields($node[2], $lookup, $whitelist, $fields);
			if($check !== true){
				return $check;
			}
			return _formula_validateFields($node[3], $lookup, $whitelist, $fields);
	}

	return ['error' => "Formula could not be parsed."];

}

/******************************************************************************/

function _formula_emit($node, $fallback, $alias){
// Generates fully parenthesized SQL from a validated AST.
// Every compound node emits exactly one paren layer; children are either
// atomic (number, column) or already parenthesized, so operator precedence
// in the output can never differ from the parse tree.
// Divisions are wrapped so divide by zero yields the fallback value
// instead of a strict-mode SQL error (NULLIF turns a zero divisor into
// NULL, and IFNULL replaces the resulting NULL with the fallback).

	switch($node[0]){
		case 'num':
			return $node[1];

		case 'field':
			return $alias.$node[1];

		case 'neg':
			return "(-"._formula_emit($node[1], $fallback, $alias).")";

		case 'op':
			$left = _formula_emit($node[2], $fallback, $alias);
			$right = _formula_emit($node[3], $fallback, $alias);

			if($node[1] === '/'){
				return "IFNULL({$left} / NULLIF({$right}, 0), {$fallback})";
			}
			return "({$left} {$node[1]} {$right})";
	}

	return '';

}
