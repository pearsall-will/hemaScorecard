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
define("FORMULA_MAX_COMPILED_LENGTH", 1000);

/******************************************************************************/

function formula_compile($source, $fallback, $whitelist, $alias = ''){
// Compiles a formula into a safe SQL expression.
//   $source    - user formula text
//   $fallback  - numeric literal used when any division divides by zero
//   $whitelist - [identifier => SQL expansion]; identifier match is
//                case-insensitive. The expansion is usually the column
//                itself, but may be column arithmetic such as
//                '(numYellowCards + numRedCards)'.
//   $alias     - table alias prefix applied to every column (e.g. 'eS.')
// Returns ['sql' => expression] or ['error' => user facing message].

	if($fallback === null || $fallback === ''){
		$fallback = '0';
	}
	if(is_string($fallback) == false && is_numeric($fallback) == false){
		return ['error' => "Divide-by-zero fallback must be a number."];
	}
	// The fallback is emitted into SQL verbatim, so it must be a plain
	// decimal literal and nothing else: optional leading minus, digits,
	// optional fraction. This rejects exponents (1e9), hex (0x1F), leading
	// or trailing dots (.5 / 5.), whitespace, and any quote, operator, or
	// comment character. The length cap keeps absurd literals out of SQL.
	$fallback = trim((string)$fallback);
	if(strlen($fallback) > 16 || preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $fallback) != 1){
		return ['error' => "Divide-by-zero fallback must be a number."];
	}

	$result = formula_tokenize($source);
	if(isset($result['error'])){
		return $result;
	}

	// Case-insensitive identifier lookup to the SQL expansion
	$lookup = [];
	foreach($whitelist as $name => $expansion){
		$lookup[strtolower($name)] = $expansion;
	}

	$state = [
		'tokens' => $result['tokens'],
		'index' => 0,
		'lookup' => $lookup,
		'validNames' => implode(', ', array_keys($whitelist)),
		'numFields' => 0,
	];

	$ast = _formula_parseExpr($state);
	if(isset($ast['error'])){
		return $ast;
	}

	if($state['index'] < count($state['tokens'])){
		$token = $state['tokens'][$state['index']];
		$safeValue = htmlspecialchars($token['value']);
		return ['error' => "Unexpected '{$safeValue}' at position ".($token['pos']+1)."."];
	}

	if($state['numFields'] == 0){
		return ['error' => "Formula must reference at least one field."];
	}

	$sql = _formula_emit($ast, $fallback, $alias);

	if(strlen($sql) > FORMULA_MAX_COMPILED_LENGTH){
		return ['error' => "Formula is too long once compiled; please simplify it."];
	}

	return ['sql' => $sql];

}

/******************************************************************************/

function formula_tokenize($source){
// Splits a formula into tokens: NUM, IDENT, OP (+ - * /), LPAREN, RPAREN.
// Numbers and identifiers span several characters, so the loop grows
// them in $buffer and flushes the finished token when a character that
// cannot extend it arrives (or at end of input).
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
	$buffer = '';
	$bufferType = null;   // 'NUM' or 'IDENT' while a token is in progress
	$bufferPos = 0;

	// Moves the in-progress number/identifier into $tokens.
	// Returns an error message string, or null on success.
	$flush = function() use (&$tokens, &$buffer, &$bufferType, &$bufferPos){
		if($bufferType === null){
			return null;
		}
		// Digits may only be split by a single '.', with digits on both sides
		if($bufferType === 'NUM' && preg_match('/^[0-9]+(\.[0-9]+)?$/', $buffer) != 1){
			return "Number '{$buffer}' must have digits after the decimal point.";
		}
		$tokens[] = ['type' => $bufferType, 'value' => $buffer, 'pos' => $bufferPos];
		$buffer = '';
		$bufferType = null;
		return null;
	};

	foreach(str_split($source) as $pos => $char){

		// Extend the token in progress if this character can belong to it
		if($bufferType === 'NUM' && (ctype_digit($char) || $char === '.')){
			$buffer .= $char;
			continue;
		}
		if($bufferType === 'IDENT' && (ctype_alnum($char) || $char === '_')){
			$buffer .= $char;
			continue;
		}

		// Otherwise this character ends it
		$error = $flush();
		if($error !== null){
			return ['error' => $error];
		}

		if(ctype_space($char)){
			continue;
		}

		if(ctype_digit($char)){
			$bufferType = 'NUM';
			$buffer = $char;
			$bufferPos = $pos;

		} elseif(ctype_alpha($char)){
			$bufferType = 'IDENT';
			$buffer = $char;
			$bufferPos = $pos;

		} elseif($char === '+' || $char === '-' || $char === '*' || $char === '/'){
			$tokens[] = ['type' => 'OP', 'value' => $char, 'pos' => $pos];

		} elseif($char === '('){
			$tokens[] = ['type' => 'LPAREN', 'value' => '(', 'pos' => $pos];

		} elseif($char === ')'){
			$tokens[] = ['type' => 'RPAREN', 'value' => ')', 'pos' => $pos];

		} else {
			$safeChar = htmlspecialchars($char);
			return ['error' => "Unexpected character '{$safeChar}' at position ".($pos+1)."."];
		}

		if(count($tokens) > FORMULA_MAX_TOKENS){
			return ['error' => "Formula is too complex (max ".FORMULA_MAX_TOKENS." tokens)."];
		}
	}

	$error = $flush();
	if($error !== null){
		return ['error' => $error];
	}
	if(count($tokens) > FORMULA_MAX_TOKENS){
		return ['error' => "Formula is too complex (max ".FORMULA_MAX_TOKENS." tokens)."];
	}

	return ['tokens' => $tokens];

}

/******************************************************************************/
// Recursive descent parser. Grammar (standard precedence):
//   expr    := term (('+' | '-') term)*
//   term    := factor (('*' | '/') factor)*
//   factor  := '-' factor | NUM | IDENT | '(' expr ')'
// Identifiers are checked against the whitelist as they are parsed and
// replaced by their SQL expansion. Nesting depth is bounded by
// FORMULA_MAX_TOKENS, so no separate depth limit is needed.
// AST nodes: ['num', value], ['field', expansion], ['neg', child],
//            ['op', operator, left, right]
// Each function returns a node or ['error' => user facing message].

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

	if($state['index'] >= count($state['tokens'])){
		return ['error' => "Formula ends unexpectedly."];
	}

	$token = $state['tokens'][$state['index']];
	$state['index']++;

	if($token['type'] === 'OP' && $token['value'] === '-'){
		$child = _formula_parseFactor($state);
		if(isset($child['error'])){
			return $child;
		}
		return ['neg', $child];
	}

	if($token['type'] === 'NUM'){
		return ['num', $token['value']];
	}

	if($token['type'] === 'IDENT'){
		$key = strtolower($token['value']);
		if(isset($state['lookup'][$key]) == false){
			$safeName = htmlspecialchars($token['value']);
			return ['error' => "Unknown field '{$safeName}'. Valid fields are: {$state['validNames']}."];
		}
		$state['numFields']++;
		return ['field', $state['lookup'][$key]];
	}

	if($token['type'] === 'LPAREN'){
		$inner = _formula_parseExpr($state);
		if(isset($inner['error'])){
			return $inner;
		}
		if(_formula_peek($state, 'RPAREN') == false){
			return ['error' => "Missing closing parenthesis."];
		}
		$state['index']++;
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
			// Prefix every column in the expansion (a bare column, or
			// column arithmetic from the whitelist) with the table alias.
			return preg_replace('/[A-Za-z_][A-Za-z0-9_]*/', $alias.'$0', $node[1]);

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
