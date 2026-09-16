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
// Recursive descent parser
//
// The parser turns the flat token list into a tree so that the emitter
// can regenerate SQL from structure alone; nothing the user typed is ever
// copied into the output. It is three mutually recursive functions, one
// per grammar rule, with the rules ordered from loosest to tightest
// binding so that operator precedence falls out of the call structure:
//
//   expr    := term (('+' | '-') term)*
//   term    := factor (('*' | '/') factor)*
//   factor  := '-' factor | NUM | IDENT | '(' expr ')'
//
// An expr is a chain of terms joined by + and -; each term is a chain of
// factors joined by * and /; so "a + b * c" parses b * c first, as a
// single term, and multiplication binds tighter without any explicit
// precedence table.
//
// Shared parser state is one array passed by reference:
//   tokens     - output of formula_tokenize()
//   index      - position of the next unread token
//   lookup     - lowercase identifier => SQL expansion (from the whitelist)
//   validNames - comma separated whitelist names for error messages
//   numFields  - count of identifiers seen, so a constant-only formula
//                can be refused
//
// Identifiers are whitelisted at the moment they are parsed, rather than
// in a separate pass over the tree, so an unknown name is reported with
// its position and no invalid tree is ever built.
//
// Nesting depth is bounded by FORMULA_MAX_TOKENS (each level costs at
// least one token), so recursion cannot run away and no separate depth
// limit is needed.
//
// Tree nodes are small positional arrays:
//   ['num', value]                  numeric literal, as typed
//   ['field', expansion]            whitelisted identifier, already
//                                   replaced by its SQL expansion
//   ['neg', child]                  unary minus
//   ['op', operator, left, right]   binary + - * /
//
// Each parse function returns a node, or ['error' => message] which every
// caller passes straight back up so the first problem wins.

function _formula_parseExpr(&$state){
// expr := term (('+' | '-') term)*
//
// Parses the loosest-binding level: one term, then any number of
// "+ term" or "- term" continuations. Each continuation folds the running
// result into the left side of a new 'op' node, which makes the chain
// left associative: "a - b - c" becomes ((a - b) - c), matching how the
// arithmetic must be evaluated.

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
//
// Same shape as _formula_parseExpr() one level down: one factor, then
// any number of "* factor" or "/ factor" continuations, folded left.
// Because expr asks for whole terms between its + and - signs, every
// * and / has already been grouped by the time expr looks at the token
// stream, which is what gives multiplication its higher precedence.

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
//
// Parses a single value, the tightest-binding level. This is the only
// function that consumes tokens other than operators, and the only one
// that can fail on a token's identity, so all "unexpected"/"unknown"
// errors originate here.
//
// A leading '-' is unary minus: it wraps the value after it in a 'neg'
// node. The tokenizer only produces unsigned numbers, so "-1" arrives as
// OP '-' then NUM '1' and is handled here too; treating it at factor
// level (rather than in the tokenizer) is what stops "wins -1" from
// being misread as wins followed by the number -1.
//
// Identifiers are looked up case-insensitively and the node stores the
// whitelist's SQL expansion instead of the typed name, so the emitter
// never sees user text.
//
// A parenthesised group recurses back to the top of the grammar and
// returns the inner tree directly; the parentheses themselves leave no
// node because the emitter re-parenthesises everything anyway.

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
// True if the next unread token has type $type and, when $values is
// given, one of those values. Does not consume the token.
//
// This is the lookahead the grammar's "( ... )*" repetitions need: expr
// and term call it to decide whether another "+ term" / "* factor"
// follows, and factor uses it to check for the closing parenthesis.
// Running off the end of the token list simply reads as "no match", so
// callers never need their own bounds checks.

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
// Walks a parsed tree and returns the SQL expression for it.
//
// The output is built only from the tree: numeric literals, whitelist
// expansions, the four operators, parentheses, and the IFNULL/NULLIF
// division guard. That is the whole security argument for user formulas:
// by the time this runs, the source text is gone.
//
// Every compound node wraps itself in exactly one pair of parentheses,
// and leaves (numbers, columns) need none, so the SQL's evaluation order
// is exactly the tree's regardless of how MySQL ranks the operators.
//
// Division is emitted as IFNULL(left / NULLIF(right, 0), fallback):
// NULLIF turns a zero divisor into NULL, dividing by NULL yields NULL
// instead of raising a strict-mode error, and IFNULL swaps that NULL for
// the tier's fallback so the fighter still gets a sortable value.
//
// $alias is prefixed to every column so the same tree can serve both the
// standings SELECT (no alias) and the self-joined tie query (eS./eS2.).

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
