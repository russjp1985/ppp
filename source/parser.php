<?php

require_once 'globals.php';
require_once 'tokens.php';

// States
define('PPP_BRACKET_ARRAY', 301);
define('PPP_BRACKET_PROP',  302);

function interlock($parts, $with) {
    $locks = array();
    foreach($parts as $part) {
        $locks[] = $part;
        $locks[] = $with;
    }
    return $locks;
}

function tokenizer($string) {
    $tokens = array();
    $string = trim($string);



    $parts = preg_split('#(\"[^\"]*\")|(\'[^\']*\')|(\#\#\#)|(\<\-)|(\-\>)|(\s+|\.|:|\]|\[|,|\(|\))#', $string, null, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $sub) {
        $token = Token::generate($sub);
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
        global $synonyms;

// Fix over zealous new-line removal

$lines = explode("\n", $content);

$output = array("<?php");

$indents = 0;
$in_block_quotes = false;

$bracket_stack = array();
$reset = true;
$synonym_list = array_keys($synonyms);

$array_stack = 0;
$rewrites = array();
$cur_token = 0;
$token_count;
$open_stack = array();

$ends_curly = false;
$in_catch = false;

foreach ($lines as $line) {
    
    $tokens = tokenizer($line);
    if ($reset) {
        $array_stack = 0;
        $rewrites = array();
        $open_stack = array();
        $ends_curly = false;
        $in_catch = false;
    }

    $token_count = count($tokens);
    $cur_token = 0;

    $reset = true;

    while ($cur_token < $token_count) {
        $prev_token = null;
        $next_token = null;
        $end = false;
        $token = $tokens[$cur_token];

        if ($cur_token == 0) {
            if ($token->value == ']' || $token->value == '}') {
                $tab_variation = -1;
            } else {
                $tab_variation = 0;
            }
            $rewrites[] = tabs($indents+$array_stack+$tab_variation);
        }

        if ($cur_token-1 >= 0) {
            $prev_token = $tokens[$cur_token-1];
        }

        // Find the next NON-whitespace token. If you reach end of the line, then keep next_token as null
        $next_token_num = $cur_token;
        while ((++$next_token_num < $token_count) && empty($next_token)) {
            $possible_next_token = $tokens[$next_token_num];
            if ($possible_next_token->type !== PPP_WHITESPACE) {
                $next_token = $possible_next_token;
                break;
            }
        }
        $cur_token++;

        //echo "Type: ({$token->value}): ".get_class($token)."\n";

        if ($token->type === PPP_BLOCKQUOTES) {
            $in_block_quotes = !$in_block_quotes;
            $rewrites[] = ($in_block_quotes) ? '/*' : '*/';
            continue;
        }

        if ($in_block_quotes) {
            $rewrites[] = $token->value;
            continue;
        }


        switch ($token->type) {
        case PPP_SELF:
            $rewrites[] = '$this';
            break;
        case PPP_PROPERTY:
        case PPP_BAREWORD:

            if ($token->type === PPP_PROPERTY) {
                $rewrites[] = '$this->'.preg_replace('/^@/', '', $token->value);
            } else {
                $rewrites[] = $token->value;
            }
            if (!$in_catch && $next_token && ($next_token->type === PPP_STRING || $next_token->type === PPP_NUMBER || $next_token->type === PPP_STATIC_SELF || $next_token->type === PPP_BOOLEAN || $next_token->type === PPP_BAREWORD || $next_token->type === PPP_VARIABLE || $next_token->type === PPP_SELF || $next_token->type === PPP_PROPERTY)) {
                $rewrites[] = '(';
                $open_stack[] = ')';
            }
            break;

        case PPP_WHITESPACE:
            $last_word = $rewrites[count($rewrites)-1];
            if (substr($last_word, -1) == '(') {
                continue;
            }
            $rewrites[] = $token->value;
            break;

        case PPP_BOOLEAN:
            if (in_array($token->value, $synonym_list)) {
                $rewrites[] = $synonyms[$token->value];
            } else {
                $rewrites[] = $token->value;
            }
            break;

        case PPP_RESERVED:
            if (in_array($token->value, $synonym_list)) {
                $rewrites[] = $synonyms[$token->value];
            } else {
                switch ($token->value) {
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

                case 'try':
                    $rewrites[] = 'try';
                    $ends_curly = true;
                    break;

                case 'catch':
                    array_pop($rewrites);
                    $indents--;
                    $rewrites[] = tabs($indents);
                    $rewrites[] = '} catch (';
                    $open_stack[] = ')';
                    $in_catch = true;
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
                    $rewrites[] = $token->value;
                }
            }
            break;

        case PPP_STATIC_SELF:
            $rewrites[] = str_replace('@@', 'self::', $token->value);
            break;

        case PPP_PUNCTUATION:
            switch ($token->value) {
            case ':':
                if ($array_stack) {
                    $rewrites[] = '=>';
                } else {
                    $rewrites[] = ':';
                }
                break;
            case '{':
                $rewrites[] = 'array(';
                $array_stack++;
                break;
            case '[':
                if ($prev_token->is(PPP_VARIABLE)) {
                    $bracket_stack[] = PPP_BRACKET_PROP;
                    $rewrites[] = '[';
                } else {
                    $bracket_stack[] = PPP_BRACKET_ARRAY;
                    $rewrites[] = 'array(';
                    $array_stack++;
                }
                break;
            case '}':
                $rewrites[] = ')';
                $array_stack--;
                break;
            case ']':
                $popped = array_pop($bracket_stack);
                if ($popped == PPP_BRACKET_ARRAY) {
                    $rewrites[] = ')';
                    $array_stack--;
                } else {
                    $rewrites[] = ']';
                }
                break;

            case '.':
                if ($next_token->is(PPP_BAREWORD)) {
                    $rewrites[] = '->';
                } else {
                    $rewrites[] = $token->value;
                }
                break;
            case '<-':
                $rewrites[] = 'return';
                break;
            default:
                $rewrites[] = $token->value;
            }
            break;
        default:
            $rewrites[] = $token->value;
        }

        if (is_null($next_token)) {
            if ($token->value == ',' || !empty($array_stack)) {
                $reset = false;
                continue;
            }
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
    if ($reset) {
        $output[] = implode('', $rewrites);
    } else {
        $rewrites[] = "\n";
    }

}
$printer =  implode("\n", $output);
return $printer;
}
}
