<?php
define('PPP_BAREWORD',    200);
define('PPP_VARIABLE',    201);
define('PPP_PUNCTUATION', 202);
define('PPP_SELF',        203);
define('PPP_STRING',      204);
define('PPP_WHITESPACE',  205);
define('PPP_INDENTATION', 206);
define('PPP_PROPERTY',    207);
define('PPP_UNKNOWN',     208);
define('PPP_RESERVED',    209);
define('PPP_BLOCKQUOTES', 210);

$punctuation = array(':', '(', ',', ')', '.', '=', '->', '+', '<<', '-');
$reserved_words = array('public', 'class', 'extends', 'private', 'else', 'elif', 'end', 'if', 'static', 'def', 'new');

function interlock($parts, $with) {
    $locks = array();
    foreach($parts as $part) {
        $locks[] = $part;
        $locks[] = $with;
    }
    return $locks;
}

function identify_token($str) {
    global $punctuation, $reserved_words;
    $trimmed = trim($str);

    if (empty($str)) {
        return null;
    }

    if ($trimmed == '###') {
        return array($str, PPP_BLOCKQUOTES, 'PPP_BLOCKQUOTES');
    }

    // Whitespace
    if (empty($trimmed)) {
        return array($str, PPP_WHITESPACE, 'PPP_WHITESPACE');
    }

    if (in_array($trimmed, $reserved_words)) {
        return array($str, PPP_RESERVED, 'PPP_RESERVED');
    }

    // Variables
    if (preg_match('/^\$[a-zA-Z][A-Za-z0-9_]*$/', $str)) {
        return array($str, PPP_VARIABLE, 'PPP_VARIABLE');
    }

    // Barewords
    if (preg_match('/^[a-zA-Z]/', $str)) {
        return array($str, PPP_BAREWORD, 'PPP_BAREWORD');
    }

    // Self 
    if ($trimmed === '@') {
        return array($str, PPP_SELF, 'PPP_SELF');
    }

    // Property
    if (preg_match('/@[a-zA-Z_]+/', $str)) {
        return array($str, PPP_PROPERTY, 'PPP_PROPERTY');
    }

    // String
    if (preg_match('/^"[^(\")]*"$/', $str) || preg_match("/^'[^(\')]*'$/", $str)) {
        return array($str, PPP_STRING, 'PPP_STRING');
    }

    // Punctuation
    if (in_array($str, $punctuation)) {
        return array($str, PPP_PUNCTUATION, 'PPP_PUNCTUATION');
    }
    return array($str, PPP_UNKNOWN, 'PPP_UNKNOWN');
}

function tokenizer($string) {
    $tokens = array();
    $string = trim($string);



    $parts = preg_split('#(\"[^\"]*\")|(\'[^\']*\')|(\#\#\#)|(\<\<)|(\-\>)|(\s+|\.|:|,|\(|\))#', $string, null, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $sub) {
        $token = identify_token($sub);
        if (!empty($token)) {
            $tokens[] = $token;
        }
    }
    return $tokens;
}

function tabs($number=0) {
    if ($number == 0) {
        return '';
    }
    return implode('', array_fill(0, $number*4, ' '));
}

class PPP_Parser {

public function parse($content) {

$content = preg_replace("/\s*\|\n\s*/", ' ', $content);
$lines = explode("\n", $content);

$output = array("<?php");

$indents = 0;
$in_block_quotes = false;

foreach ($lines as $line) {
    $tokens = tokenizer($line);
    $rewrites = array();
    $cur_token = 0;
    $token_count = count($tokens);
    $open_stack = array();
    $rewrites[] = tabs($indents);

    $ends_curly = false;

    while ($cur_token < $token_count) {
        $prev_token = null;
        $next_token = null;
        $end = false;
        $token = $tokens[$cur_token];
        if ($cur_token-1 >= 0) {
            $prev_token = $tokens[$cur_token-1];
        }

        // Find the next NON-whitespace token. If you reach end of the line, then keep next_token as null
        $next_token_num = $cur_token;
        while ((++$next_token_num < $token_count) && empty($next_token)) {
            $possible_next_token = $tokens[$next_token_num];
            if ($possible_next_token[1] !== PPP_WHITESPACE) {
                $next_token = $possible_next_token;
                break;
            }
        }
        $cur_token++;

        if ($token[1] === PPP_BLOCKQUOTES) {
            $in_block_quotes = !$in_block_quotes;
            $rewrites[] = ($in_block_quotes) ? '/*' : '*/';
            continue;
        }

        if ($in_block_quotes) {
            $rewrites[] = $token[0];
            continue;
        }


        switch ($token[1]) {
        case PPP_SELF:
            $rewrites[] = '$this';
            break;
        case PPP_PROPERTY:
        case PPP_BAREWORD:

            if ($token[1] === PPP_PROPERTY) {
                $rewrites[] = '$this->'.preg_replace('/^@/', '', $token[0]);
            } else {
                $rewrites[] = $token[0];
            }
            if ($next_token && ($next_token[1] === PPP_STRING || $next_token[1] === PPP_BAREWORD || $next_token[1] === PPP_VARIABLE || $next_token[1] === PPP_SELF || $next_token[1] === PPP_PROPERTY)) {
                $rewrites[] = '(';
                $open_stack[] = ')';
            }
            break;

        case PPP_WHITESPACE:
            $last_word = $rewrites[count($rewrites)-1];
            if (substr($last_word, -1) == '(') {
                continue;
            }
            $rewrites[] = $token[0];
            break;
        case PPP_RESERVED:
            switch ($token[0]) {
            case '(':
                $open_stack[] = ')';
                $rewrites[] = '(';
                break;
            case ')':
                array_pop($open_stack);
                $rewrites[] = ')';
                break;

            case 'end':
                array_pop($rewrites);
                $indents--;
                $rewrites[] = tabs($indents);
                $rewrites[] = '}';
                $end = true;
                break;

            case 'if':
                $rewrites[] = 'if (';
                $open_stack[] = ')';
                $ends_curly = true;
                break;
            case 'def':
                $rewrites[] = 'function';
                $ends_curly = true;
                break;

            case 'else':
                array_pop($rewrites);
                $indents--;
                $rewrites[] = tabs($indents);
                $rewrites[] = '} else';
                $ends_curly = true;
                break;

            case 'elif':
                array_pop($rewrites);
                $indents--;
                $rewrites[] = tabs($indents);
                $rewrites[] = '} else if (';
                $open_stack[] = ')';
                $ends_curly = true;
                break;

            case 'class':
                $ends_curly = true;
            default:
                $rewrites[] = $token[0];
            }
            break;

        case PPP_PUNCTUATION:
            switch ($token[0]) {
            case '<<':
                $rewrites[] = 'return';
                break;
            default:
                $rewrites[] = $token[0];
            }
            break;
        default:
            $rewrites[] = $token[0];
        }

        if (is_null($next_token)) {
            while (!empty($open_stack)) {
                $rewrites[] = array_pop($open_stack);
            }

            if ($ends_curly) {
                $rewrites[] = ' {';
                $indents++;
            } else if (!$end) {
                $rewrites[] = ';';
            }
        }
    }
    $output[] = implode('', $rewrites);

}
return implode("\n", $output);
}
}