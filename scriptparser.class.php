<?php
/**
 * This class parses HadesScripts
 *
 * @license  http://opensource.org/licenses/bsd-license
 * @author   Christian Neff <christian.neff@gmail.com>
 * @author   Miles Kaufmann <http://www.twmagic.com>
 */
class scriptparser {

    public $silent = false;
    
    public $throwErrors = true;
    
    public $libsDir = 'scriptlibs';
    
    public $variables = array();
    
    public $functions = array();
    
    public $namespace = null;
    
    private $messages = array();
    
    private $_line = 0;
    
    private $_zone = null;
    
    private $_level = 0;
    
    private $_capture = false;
    
    private $_cache = array();
    
    public function __construct($libs = array('math', 'string', 'array')) {
        foreach ($libs as $lib) {
            $class = 'scriptlib_'.$lib;
            $namespace = str_replace('_', ':', $lib);
            include $this->libsDir.'/'.$class.'.class.php';
            $this->importFunctions($class, $namespace);
        }
    }
    
    public function __get($varName) {
        if ($varName[0] != '_') {
            return $this->$varName;
        }
    }
    
    public function execute($code, $zone = null, $protected = false, $vars = array()) {
        // protected mode active?
        if ($protected) {
            $varsBackup = array();
            // walk through all registered variables
            foreach ($this->variables as $varName => $varRecord) {
                // if a variable is local, back it up and unset it
                if ($this->variables[$varName]->scope == 0) {
                    $varsBackup[$varName] = $varRecord;
                    unset($this->variables[$varName]);
                }
            }
            // if there are predefined variables given, add them to the variable registry
            if (is_array($vars) && !empty($vars)) {
                foreach ($vars as $varName => $varValue) {
                    $this->declareVariable($varName, $varValue);
                }
            }
        }
        $lines = explode("\n", $code);
        $lineCount = count($lines);
        $block = new scriptparser_blockStack($this->_level);
        for ($lineNumber = 1; $lineNumber <= $lineCount; $lineNumber++) {
            $this->_line = $lineNumber;
            $this->_zone = $zone;
            $line = trim($lines[$lineNumber-1]);
            if ($line != '' && substr($line, 0, 1) != ';') {
                if (!preg_match('/^([a-zA-Z_][\w:]*)(?:\s+(.+))*$/', $line, $matches))
                    return $this->_triggerMessage('Invalid command', 2);
                $command = $matches[1]; $parameters = (isSet($matches[2]) ? $matches[2] : false);
                switch ($command) {
                    case 'return':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!$block->get('active'))
                            continue;
                        $return = $this->evaluateFormula($parameters);
                        break 2;
                    case 'echo':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!$block->get('active'))
                            continue;
                        $message = $this->evaluateFormula($parameters);
                        if (!$this->silent) {
                            echo $message;
                        } else {
                            $this->_triggerMessage($message, 0);
                        }
                        break;
                    case 'eval':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!$block->get('active'))
                            continue;
                        $code = (string) $this->evaluateFormula($parameters);
                        $this->execute($code);
                        break;
                    case 'import':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!$block->get('active'))
                            continue;
                        $file = (string) $this->evaluateFormula($parameters);
                        $this->executeFile($file);
                        break;
                    case 'namespace':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!$block->get('active'))
                            continue;
                        if ($parameters == 'global') {
                            $this->namespace = null;
                        } else {
                            $this->namespace = (string) $this->evaluateFormula($parameters);
                        }
                        break;
                    case 'var':
                    case 'global':
                    case 'const':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!$block->get('active'))
                            continue;
                        // what type?
                        if ($command == 'var') {
                            $varType = 0;
                        } elseif ($command == 'global') {
                            $varType = 1;
                        } elseif ($command == 'const') {
                            $varType = 2;
                        }
                        // syntax okay?
                        if ($parameters) {
                            $vars = $this->_walkAssignList($parameters);
                        } else {
                            return _triggerMessage('Invalid syntax', 2);
                        }
                        // declare each variable
                        foreach ($vars as $varName => $varValue) {
                            // make sure that it is not declared yet
                            if (isSet($this->variables[$varName])) {
                                $this->_triggerMessage('Cannot redeclare variable $'.$varName);
                                continue;
                            }
                            // stick it in the variable array
                            $this->declareVariable($varName, !is_null($varValue) ? $varValue : false, $varType);
                        }
                        break;
                    case 'set':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!$block->get('active'))
                            continue;
                        if (!preg_match('/^\$([a-zA-Z_][\w\.]*)\s*(=|\+|-|\*|\/)\s*(.+)$/', $parameters, $matches))
                            return $this->_triggerMessage('Invalid syntax', 2);
                        $varPath = $matches[1]; $varOperation = $matches[2]; $varValue = $matches[3];
                        list($varName) = explode('.', $varPath);
                        // make sure we're not re-assigning a constant
                        if ($this->variables[$varName]->scope == 2) {
                            $this->_triggerMessage('Cannot set constant $'.$varName);
                            continue;
                        }
                        // operation type?
                        switch ($varOperation) {
                            case '=':
                                $varValueResult = $varValue;
                                break;
                            case '+':
                                $variable = $this->getVariable($varPath);
                                if (is_array($variable)) {
                                    array_push($variable, $varValue);
                                    $varValueResult = $variable;
                                } elseif (is_string($variable)) {
                                    $varValueResult = $variable . $varValue;
                                } else {
                                    $varValueResult = $variable + $varValue;
                                }
                                break;
                            case '-':
                                $variable = $this->getVariable($varPath);
                                if (is_string($variable)) {
                                    $varValueResult = str_replace($varValue, '', $variable);
                                } else {
                                    $varValueResult = $variable - $varValue;
                                }
                                break;
                            case '*':
                                $varValueResult = $this->getVariable($varPath) * $varValue;
                                break;
                            case '/':
                                $varValueResult = $this->getVariable($varPath) / $varValue;
                                break;
                            default:
                                $this->_triggerMessage('Invalid assignment operator \''.$varOperation.'\'');
                                break;
                        }
                        // stick it in the variable array
                        $this->setVariable($varPath, $varValueResult);
                        break;
                    case 'if':
                        $this->_level++;
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        $active = false;
                        if ($block->get('active', 1)) {
                            if ((bool) $this->evaluateFormula($parameters))
                                $active = true;
                        }
                        $block->update('if', $active);
                        break;
                    case 'elseif':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        $active = false;
                        if ($this->_capture) {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if ($block->get('active', 1) && !$block->get('done')) {
                            if ((bool) $this->evaluateFormula($parameters)) {
                                $active = true;
                            }
                        }
                        $block->update('if', $active);
                        break;
                    case 'else':
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        $active = false;
                        if ($block->get('active', 1) && !$block->get('done')) {
                            $active = true;
                        }
                        $block->update('if', $active);
                        break;
                    case 'while':
                        $this->_level++;
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        $active = false; $loopBegin = false;
                        if ($block->get('active', 1) && ($block->isRegistered() ? $block->get('active') : true)) {
                            if ((bool) $this->evaluateFormula($parameters)) {
                                $active = true;
	                            $loopBegin = $lineNumber;
                            }
                        }
                        $block->update('while', $active, array('loopBegin' => $loopBegin));
                        break;
                    case 'for':
                        $this->_level++;
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!preg_match('/^\$([a-zA-Z_]\w*)\s*=\s*(.+)\s+to\s+(.+)$/', $parameters, $matches))
                            return $this->_triggerMessage('Invalid syntax in for block', 2);
                        $varName = $matches[1];
                        $varStart = $this->evaluateFormula($matches[2]);
                        if (preg_match('/^(.+)\s+step\s+(.+)$/', $matches[3], $submatches)) {
                            $varEnd = (int) $this->evaluateFormula($submatches[1]);
                            $varStep = (int) $this->evaluateFormula($submatches[2]);
                        } else {
                            $varEnd = (int) $this->evaluateFormula($matches[3]);
                            $varStep = 1;
                        }
                        if ($block->get('active', 1) && !$block->isRegistered()) {
                            $block->update('for', true, array('loopBegin' => $lineNumber));
                            $this->declareVariable($varName, $varStart);
                        } elseif ($block->get('active', 1) && $this->variables[$varName]->value != $varEnd) {
                            if ($varEnd > $varStart) {
                                $this->variables[$varName]->value += $varStep;
                            } elseif ($varEnd < $varStart) {
                                $this->variables[$varName]->value -= $varStep;
                            }
                        } else {
                            $block->update('for', false);
                        }
                        break;
                    case 'foreach':
                        $this->_level++;
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        if (!preg_match('/^\$([a-zA-Z_]\w*)\s+in\s+(.+)$/', $parameters, $matches))
                            return $this->_triggerMessage('Invalid variable name for foreach block', 2);
                        $varName = $matches[1];
                        $inputArray = $this->evaluateFormula($matches[2]);
                        if (!is_array($inputArray))
                            return $this->_triggerMessage("Invalid argument supplied for foreach block", 2);
                        $active = false; $loopBegin = false; $activeNow = true; $iterationNow = 0;
                        if ($block->isRegistered()) {
                            $activeNow = $block->get('active');
                            $iterationNow = $block->get('iteration');
                        } else {
                            $this->declareVariable($varName, array());
                        }
                        if ($block->get('active', 1) && $activeNow) {
                            $last = count($inputArray)-1;
                            $keys = array_keys($inputArray);
                            $values = array_values($inputArray);
                            $offset = $iterationNow;
                            if ($offset <= $last) {
                                $active = true;
	                            $loopBegin = $lineNumber;
	                            $this->variables[$varName]->value['key'] = $keys[$offset];
	                            $this->variables[$varName]->value['value'] = $values[$offset];
                            }
                        }
                        $block->update('foreach', $active, array('loopBegin' => $loopBegin));
                        break;
                    case 'macro':
                        $this->_level++;
                        if ($block->get('type') == 'macro') {
                            $this->_cache[] = $line;
                            continue;
                        }
                        // fetch name and raw arguments
                        if (preg_match('/^([a-zA-Z_]\w*)\s+(.+)$/', $parameters, $matches)) {
                            $funcName = $matches[1];
                            $funcArgsList = $matches[2];
                        } elseif (preg_match('/^[a-zA-Z_]\w*$/', $parameters)) {
                            $funcName = $parameters;
                            $funcArgsList = false;
                        } else {
                            return $this->_triggerMessage('Invalid syntax in function definition', 2);
                        }
                        // make sure it isn't defined yet
                        if (isSet($this->functions[$funcName]))
                            return $this->_triggerMessage('Cannot redefine function {'.$funcName.'}', 2);
                        // get the arguments
                        $funcArgs = array(); $funcArgDefaults = array();
                        if ($funcArgsList) {
                            foreach ($this->_walkAssignList($funcArgsList) as $key => $value) {
                                $funcArgs[] = $key;
                                if (!is_null($value))
                                    $funcArgDefaults[$key] = $value;
                            }
                        }
                        // register block
                        $options = array(
                            'name' => $funcName,
                            'args' => $funcArgs,
                            'argDefaults' => $funcArgDefaults
                        );
                        $block->update('macro', true, $options);
                        break;
                    case 'end':
                        if ($block->get('loop') && $block->get('active')) {
                            $lineNumber = $block->get('loopBegin')-1;
                        } else {
                            if ($block->get('type') == 'macro') {
                                $sequence = implode("\n", $this->_cache); $this->_cache = array();
                                $this->createFunction($this->namespace, $block->get('name'), $sequence, $block->get('args'), $block->get('argDefaults'));
                            }
                            $block->remove();
                        }
                        $this->_level--;
                        break;
                    default:
                        if (!$block->get('active'))
                            continue;
                        // get function name and check if the function exists
                        if (!isSet($this->functions[$command]))
                            return $this->_triggerMessage('Call to undefined function {'.$command.'}', 2);
                        // build arguments array
                        if ($parameters) {
                            $funcArgs = $this->_walkValueList($parameters);
                        } else {
                            $funcArgs = array();
                        }
                        // cast the function
                        $this->castFunction($command, $funcArgs);
                        break;
                }
            }
        }
        // is the level higher than 0? if yes, there is a missing block end
        if ($this->_level > 0)
            return $this->_triggerMessage('Missing block end', 2);
        // protected mode active?
        if ($protected) {
            // remove all temporarily added local variables
            foreach ($this->variables as $varName => $varRecord) {
                if ($this->variables[$varName]->scope == 0) {
                    unset($this->variables[$varName]);
                }
            }
            // restore all previously backed up variables to the variable registry
            $this->variables = array_merge($this->variables, $varsBackup);
        }
        return (isSet($return) ? $return : true);
    }
    
    public function executeFile($file) {
        if (($code = file_get_contents($file)) === false)
            return trigger_error('scriptparser: Could not open script file \''.$file.'\'', E_USER_ERROR);
        return $this->execute($code, realpath($file));
    }
    
    public function evaluateFormula($formula) {
        $tokens = $this->_tokenizeFormula($formula);
        if ($tokens === false)
            return false;
        return $this->_computeFormula($tokens);
    }
    
    public function getVariable($path) {
        $pathParts = explode('.', $path);
        $name = $pathParts[0];
        $key = array_slice($pathParts, 1);
        // generate skeleton
        $varSkeleton = "\$this->variables['{$name}']->value";
        if (!empty($key)) {
            foreach ($key as $keyPart) {
                if (preg_match('/^\d+$/', $keyPart)) {
                    $varSkeleton .= '['.$keyPart.']';
                } elseif (preg_match('/^[a-zA-Z_]\w*$/', $keyPart)) {
                    $varSkeleton .= '[\''.$keyPart.'\']';
                } else {
                    trigger_error('scriptparser: Invalid key name for variable', E_USER_WARNING);
                    return null;
                }
            }
        }
        // get the variable
        return eval("return (isSet(\$this->variables['{$name}']) ? {$varSkeleton} : false);");
    }
    
    public function declareVariable($name, $value, $scope = 0) {
        if (strpos($name, '.') !== false) {
            trigger_error('scriptparser: Declaring a variable\'s subvalue is illegal', E_USER_WARNING);
            return false;
        }
        $this->variables[$name] = new scriptparser_variable($value, $scope);
        return true;
    }
    
    public function setVariable($path, $value) {
        $pathParts = explode('.', $path);
        $name = $pathParts[0];
        // is it not yet declared? then declare it as local variable
        if (!isSet($this->variables[$name]))
            $this->declareVariable($name, $value, 0);
        // get the key
        $key = array_slice($pathParts, 1);
        // generate skeleton
        $varSkeleton = "\$this->variables['{$name}']->value";
        if (!empty($key)) {
            foreach ($key as $keyPart) {
                if (preg_match('/^\d+$/', $keyPart)) {
                    $varSkeleton .= "[{$keyPart}]";
                } elseif (preg_match('/^[a-zA-Z_]\w*$/', $keyPart)) {
                    $varSkeleton .= "['{$keyPart}']";
                } else {
                    trigger_error('scriptparser: Invalid key name for variable', E_USER_WARNING);
                    return null;
                }
            }
        }
        // get the variable
        eval("\$varRef =& {$varSkeleton};");
        $varRef = $value;
        return true;
    }
    
    public function castFunction($name, array $arguments) {
        // is function defined?
        if (isSet($this->functions[$name])) {
            $func = $this->functions[$name];
        } else {
            trigger_error('scriptparser: Call to undefined function {'.$name.'}', E_USER_WARNING);
            return false;
        }
        // make a list of predefined variables (arguments)
        $predefinedVars = array();
        foreach ($func->arguments as $argIndex => $argName) {
            if (isSet($arguments[$argIndex])) {
                $predefinedVars[$argName] = $arguments[$argIndex];
            } elseif (isSet($func->argDefaults[$argName])) {
                $predefinedVars[$argName] = $func->argDefaults[$argName];
            } else {
                $this->_triggerMessage('No default value for argument $'.$argName.' of {'.$name.'}');
                $predefinedVars[$argName] = false;
            }
        }
        // what type?
        if ($func->type == 'callback') {
            // is it a PHP callback...
            return call_user_func_array($func->sequence, $predefinedVars);
        } elseif ($func->type = 'code') {
            // ... or some HadesScript code?
            return $this->execute($func->sequence, '{'.$name.'}', true, $predefinedVars);
        } else {
            // something else
            return false;
        }
    }
    
    public function createFunction($namespace = null, $name, $sequence, array $arguments, array $argDefaults = array()) {
        $func = new scriptparser_function($name, $sequence, $arguments, $argDefaults);
        if (is_string($namespace))
            $name = $namespace . ':' . $name;
        $this->functions[$name] = $func;
        return true;
    }
    
    public function importFunctions($class, $namespace = null) {
        if (!class_exists($class)) {
            trigger_error('scriptparser: Class \''.$class.'\' does not exist', E_USER_WARNING);
            return false;
        }
        $reflectClass = new ReflectionClass($class);
        foreach ($reflectClass->getMethods() as $reflectMethod) {
            $funcName = $reflectMethod->name;
            $funcArgs = array(); $funcArgDefaults = array();
            if ($reflectMethod->getNumberOfParameters() > 0) {
                foreach ($reflectMethod->getParameters() as $reflectParameter) {
                    $funcArgs[] = $reflectParameter->name;
                    if ($reflectParameter->isOptional()) {
                        $funcArgDefaults[$reflectParameter->name] = $reflectParameter->getDefaultValue();
                    }
                }
            }
            $this->createFunction($namespace, $funcName, array($class, $funcName), $funcArgs, $funcArgDefaults);
        }
        return true;
    }
    
    private function _tokenizeFormula($expression) {
        $index = 0;
        $stack = new scriptparser_mathStack;
        $output = array();
        $depth = array('std' => 0, 'sts' => 0, 'arr' => 0, 'fnc' => 0);
        $escape = false;
        $buffer = '';
        $expectingOperator = false; // we use this for syntax-checking and determining if a '-' is a negation
        
        // all allowed operators
        $operators = array('+', '-', '*', '/', '%', '^', '_');
        $operatorsRightAssoc = array('+' => 0, '-' => 0, '*' => 0, '/' => 0, '%' => 0, '_' => 0, '^' => 1);
        $operatorsPrecedence = array('+' => 0, '-' => 0, '*' => 1, '/' => 1, '%' => 1, '_' => 1, '^' => 2);
        
        $expression = trim($expression);
        while (true) {
            // get the first character at the current index
            $char = $expression[$index];
            // find out if we're currently at the beginning of a number/variable/parenthesis/operand
            $atBeginning = preg_match('/^([a-zA-Z_]+|\$[a-zA-Z_][\w\.]*|\d+(?:\.\d*)?|\.\d+|\()/', substr($expression, $index), $match);
            if ($char == "'" && $depth['arr'] == 0 && $depth['fnc'] == 0 && $depth['std'] == 0) {
                $buffer .= $char;
                $index++;
                if ($depth['sts'] == 0) {
                    $depth['sts']++;
                    $expectingOperator = false;
                } elseif (!$escape) {
                    $depth['sts']--;
                    $output[] = $buffer;
                    $buffer = '';
                    $expectingOperator = true;
                }
            } elseif ($char == '"' && $depth['arr'] == 0 && $depth['fnc'] == 0 && $depth['sts'] == 0) {
                $buffer .= $char;
                $index++;
                if ($depth['std'] == 0) {
                    $depth['std']++;
                    $expectingOperator = false;
                } elseif (!$escape) {
                    $depth['std']--;
                    $output[] = $buffer;
                    $buffer = '';
                    $expectingOperator = true;
                }
            } elseif ($char == '[' && $depth['fnc'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $buffer .= $char;
                $index++;
                $depth['arr']++;
                $expectingOperator = false;
            } elseif ($char == ']' && $depth['fnc'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $buffer .= $char;
                $index++;
                if ($depth['arr'] >= 1) {
                    $depth['arr']--;
                    if ($depth['arr'] == 0) {
                        $output[] = $buffer;
                        $buffer = '';
                        $expectingOperator = true;
                    }
                } else {
                    return $this->_triggerMessage('Unexpected closing array delimiter', 2);
                }
            } elseif ($char == '{' && $depth['arr'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $buffer .= $char;
                $index++;
                $depth['fnc']++;
                $expectingOperator = false;
            } elseif ($char == '}' && $depth['arr'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $buffer .= $char;
                $index++;
                if ($depth['fnc'] >= 1) {
                    $depth['fnc']--;
                    if ($depth['fnc'] == 0) {
                        $output[] = $buffer;
                        $buffer = '';
                        $expectingOperator = true;
                    }
                } else {
                    return $this->_triggerMessage('Unexpected closing function call delimiter', 2);
                }
            } elseif (!$escape && $char == '\\' && ($depth['sts'] == 1 || $depth['std'] == 1)) {
                $buffer .= '\\';
                $escape = true;
                $index++;
            } elseif ($depth['sts'] == 1 || $depth['std'] == 1 || $depth['arr'] >= 1 || $depth['fnc'] >= 1) {
                $escape = false;
                $buffer .= $char;
                $index++;
            } elseif ($char == '-' && !$expectingOperator) {
                // is it a negation instead of a minus? put a negation on the stack
                $stack->push('_');
                $index++;
            } elseif ($char == '_') {
                // we have to explicitly deny this, because it's legal on the stack and therefore not in the
                // input expression
                return $this->_triggerMessage('Unexpected character \'_\'', 2);
            } elseif ((in_array($char, $operators) || in_array($char, array('&', '|', '~', '=', '!', '<', '>')) || $atBeginning) && $expectingOperator) {
                // are we putting an operator on the stack?
                if ($atBeginning) {
                    // are we expecting an operator but have a number/variable/function/opening parenthesis?
                    // then it's an implicit multiplication
                    $char = '*';
                    $index--;
                }
                // the heart of the algorithm
                // many thanks: http://en.wikipedia.org/wiki/Reverse_Polish_notation#The_algorithm_in_detail
                while ($stack->count > 0 && ($operator2 = $stack->last()) && in_array($operator2, $operators) && ($operatorsRightAssoc[$char] ? $operatorsPrecedence[$char] < $operatorsPrecedence[$operator2] : $operatorsPrecedence[$char] <= $operatorsPrecedence[$operator2])) {
                    // pop stuff off the stack into the output
                    $output[] = $stack->pop();
                }
                // logical and comparison operators
                if ($char == '&' || $char == '|' || $char == '~') {
                    $output[] = $stack->pop();
                    $stack->push($char);
                    $index++;
                } elseif ($char == '=' || $char == '!') {
                    if ($expression[$index + 1] == '=' && $expression[$index + 2] == '=') {
                        $stack->push($char . '==');
                        $index += 3;
                    } elseif ($expression[$index + 1] == '=') {
                        $stack->push($char . '=');
                        $index += 2;
                    } else {
                        return $this->_triggerMessage('Unexpected character \''.$char.'\'', 2);
                    }
                } elseif ($char == '<' || $char == '>') {
                    if ($expression[$index + 1] == '=') {
                        $stack->push($char . '=');
                        $index += 2;
                    } else {
                        $stack->push($char);
                        $index++;
                    }
                } else {
                    $stack->push($char);
                    $index++;
                }
                // finally put our operator onto the stack
                $expectingOperator = false;
            } elseif ($char == ')' && $expectingOperator) {
                // ready to close a parenthesis?
                while (($operator2 = $stack->pop()) != '(') {
                    // pop off the stack back to the last (
                    if (is_null($operator2)) {
                        return $this->_triggerMessage('Unexpected closing parenthesis', 2);
                    } else {
                        $output[] = $operator2;
                    }
                }
                $index++;
            } elseif ($char == '(' && !$expectingOperator) {
                // that was easy
                $stack->push('(');
                $index++;
                $allowNegation = true;
            } elseif ($atBeginning && !$expectingOperator) {
                // do we now have a variable/value?
                $expectingOperator = true;
                $val = $match[1];
                $output[] = $val;
                $index += strlen($val);
            } elseif ($char == ')') {
                // miscellaneous error checking
                return $this->_triggerMessage('Unexpected closing parenthesis', 2);
            } elseif (in_array($char, $operators) && !$expectingOperator) {
                // unexpected operator
                return $this->_triggerMessage('Unexpected operator \''.$char.'\'', 2);
            } else {
                // unknown character
                return $this->_triggerMessage('Unexpected character \''.$char.'\'', 2);
            }
            if ($index == strlen($expression)) {
                if (in_array($char, $operators)) {
                    return $this->_triggerMessage('Invalid or missing operand', 2);
                } else {
                    break;
                }
            }
            while ($depth['sts'] == 0 && $depth['std'] == 0 && $depth['arr'] == 0 && $depth['fnc'] == 0 && substr($expression, $index, 1) == ' ') {
                // step the index past whitespace (pretty much turns whitespace into implicit multiplication
                // if no operator is there)
                $index++;
            }
        }
        
        // is everything okay?
        if ($depth['sts'] >= 1 || $depth['std'] >= 1)
            return $this->_triggerMessage('Missing closing string delimiter', 2);
        if ($depth['arr'] >= 1)
            return $this->_triggerMessage('Missing closing array delimiter', 2);
        if ($depth['fnc'] >= 1)
            return $this->_triggerMessage('Missing closing function call delimiter', 2);
            
        // pop everything off the stack and push onto output if there are (s on the stack, ()s were unbalanced
        while (!is_null($char = $stack->pop())) {
            if ($char == '(')
                return $this->_triggerMessage('Missing closing parenthesis', 2);
            $output[] = $char;
        }
        
        return $output;
    }
    
    private function _computeFormula(array $tokens, array $vars = array()) {
        if ($tokens == false)
            return false;
        $operators = array('+', '-', '*', '/', '%', '^', '&', '|', '~', '==', '===', '!=', '!==', '<', '<=', '>', '>=');
        $stack = new scriptparser_mathStack;
        foreach ($tokens as $token) {
            if (in_array($token, $operators)) {
                // if the token is a binary operator, pop two values off the stack, do the operation, and push
                // the result back on
                if (is_null($operand2 = $stack->pop()))
                    return $this->_triggerMessage('Invalid second operand', 2);
                if (is_null($operand1 = $stack->pop()))
                    return $this->_triggerMessage('Invalid first operand', 2);
                switch ($token) {
                    case '+':
                        if (is_array($operand1) && is_array($operand2)) {
                            $stack->push(array_merge($operand1, $operand2));
                        } elseif (is_string($operand1)) {
                            $stack->push($operand1 . $operand2);
                        } else {
                            $stack->push($operand1 + $operand2);
                        }
                        break;
                    case '-':
                        if (is_array($operand1) && is_array($operand2)) {
                            $stack->push(array_diff($operand1, $operand2));
                        } else {
                            $stack->push($operand1 - $operand2);
                        }
                        break;
                    case '*':
                        $stack->push($operand1 * $operand2);
                        break;
                    case '/':
                        if ($operand2 == 0)
                            return $this->_triggerMessage('Division by zero');
                        $stack->push($operand1 / $operand2);
                        break;
                    case '%':
                        $stack->push($operand1 % $operand2);
                        break;
                    case '^':
                        $stack->push(pow($operand1, $operand2));
                        break;
                    case '&':
                        $stack->push($operand1 && $operand2);
                        break;
                    case '|':
                        $stack->push($operand1 || $operand2);
                        break;
                    case '~':
                        $stack->push($operand1 xor $operand2);
                        break;
                    case '==':
                        $stack->push($operand1 == $operand2);
                        break;
                    case '!=':
                        $stack->push($operand1 != $operand2);
                        break;
                    case '===':
                        $stack->push($operand1 === $operand2);
                        break;
                    case '!==':
                        $stack->push($operand1 !== $operand2);
                        break;
                    case '<':
                        $stack->push($operand1 < $operand2);
                        break;
                    case '<=':
                        $stack->push($operand1 <= $operand2);
                        break;
                    case '>':
                        $stack->push($operand1 > $operand2);
                        break;
                    case '>=':
                        $stack->push($operand1 >= $operand2);
                        break;
                }
            } elseif ($token == '_') {
                // if the token is a unary operator, pop one value off the stack, do the operation, and push
                // it back on
                $stack->push(-1 * $stack->pop());
            } elseif (preg_match('/^(true|false)$/i', $token) || is_numeric($token)) {
                // it's a boolean value or a numeric value
                eval('$stack->push('.$token.');');
            } elseif ($this->_isEnclosure("'", $token, $result)) {
                // it's a simple string
                eval('$stack->push(\''.$result.'\');');
            } elseif ($this->_isEnclosure('"', $token, $result)) {
                // it's a double-quoted string
                $string = str_replace('$', '\$', $result);
                eval('$stack->push("'.$string.'");');
            } elseif ($this->_isEnclosure(array('[', ']'), $token, $result)) {
                // it's an array
                if ($result == '') {
                    // the array is empty
                    $stack->push(array());
                    continue;
                }
                // get the values and push the result onto the stack
                $pushVal = $this->_walkValueList($result, true);
                $stack->push($pushVal);
            } elseif ($this->_isEnclosure(array('{', '}'), $token, $result)) {
                // it's a function call
                if (preg_match('/^([a-zA-Z_][\w:]*)\s+(.+)$/', $result, $matches)) {
                    $funcName = $matches[1];
                    $funcArgsList = $matches[2];
                } elseif (preg_match('/^[a-zA-Z_][\w:]*$/', $result)) {
                    $funcName = $result;
                    $funcArgsList = false;
                } else {
                    return $this->_triggerMessage('Invalid syntax in function call', 2);
                }
                // check if the function already exists
                if (!isSet($this->functions[$funcName]))
                    return $this->_triggerMessage('Call to undefined function {'.$funcName.'}', 2);
                // build arguments array
                if ($funcArgsList) {
                    $funcArgs = $this->_walkValueList($funcArgsList);
                } else {
                    $funcArgs = array();
                }
                // cast the function and push the result onto the stack
                $pushVal = $this->castFunction($funcName, $funcArgs);
                $stack->push($pushVal);
            } elseif (preg_match('/^\$([a-zA-Z_][\w\.]*)$/', $token, $matches)) {
                // it's a value represented by a variable
                $stack->push($this->getVariable($matches[1]));
            } else {
                // not a valid value
                return $this->_triggerMessage('Invalid value \''.$token.'\'');
            }
        }
        // when we're out of tokens, the stack should have a single element, the final result
        if ($stack->count != 1)
            trigger_error('scriptparser: Unexpected internal error', E_USER_ERROR);
        return $stack->pop();
    }
    
    private function _isEnclosure($char, $input, &$result = null) {
        if (is_array($char)) {
            $charBefore = $char[0];
            $charBeforeLen = strlen($charBefore);
            $charAfter = $char[1];
            $charAfterLen = strlen($charAfter);
            if (substr($input, 0, $charBeforeLen) == $charBefore && substr($input, 0 - $charAfterLen, $charAfterLen) == $charAfter) {
                $result = substr($input, $charBeforeLen, 0 - $charAfterLen);
                return true;
            }
        } else {
            $charLen = strlen($char);
            if (substr($input, 0, $charLen) == $char && substr($input, 0 - $charLen, $charLen) == $char) {
                $result = substr($input, $charLen, 0 - $charLen);
                return true;
            }
        }
        $result = null;
        return false;
    }
    
    private function _walkValueList($input, $keying = false) {
        $return = array(); $buffer = '';
        $depth = array('std' => 0, 'sts' => 0, 'arr' => 0, 'fnc' => 0); $escape = false;
        $end = strlen($input); $chars = str_split($input);
        for ($pos = 0; $pos <= $end; $pos++) {
            $char = $chars[$pos];
            if ($char == "'" && $depth['arr'] == 0 && $depth['fnc'] == 0 && $depth['std'] == 0) {
                if ($depth['sts'] == 0) {
                    $depth['sts']++;
                } elseif (!$escape) {
                    $depth['sts']--;
                }
            } elseif ($char == '"' && $depth['arr'] == 0 && $depth['fnc'] == 0 && $depth['sts'] == 0) {
                if ($depth['std'] == 0) {
                    $depth['std']++;
                } elseif (!$escape) {
                    $depth['std']--;
                }
            } elseif ($char == '[' && $depth['fnc'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $depth['arr']++;
            } elseif ($char == ']' && $depth['fnc'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $depth['arr']--;
            } elseif ($char == '{' && $depth['arr'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $depth['fnc']++;
            } elseif ($char == '}' && $depth['arr'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $depth['fnc']--;
            } elseif (!$escape && $char == '\\' && ($depth['sts'] == 1 || $depth['std'] == 1)) {
                $escape = true;
            } else {
                $escape = false;
            }
            $onTop = $depth['sts'] == 0 && $depth['std'] == 0 && $depth['arr'] == 0 && $depth['fnc'] == 0;
            if (($char == ',' && $onTop) || $pos == $end) {
                if ($keying && preg_match('/^([a-zA-Z_]\w*)\s*:\s*(.+)$/', $buffer, $matches)) {
                    $output[$matches[1]] = $this->evaluateFormula($matches[2]);
                } else {
                    $return[] = $this->evaluateFormula($buffer);
                }
                $buffer = '';
            } elseif ($char == ' ' && $onTop) {
                continue;
            } else {
                $buffer .= $char;
            }
        }
        return $return;
    }
    
    private function _walkAssignList($input) {
        $return = array(); $buffer = '';
        $depth = array('std' => 0, 'sts' => 0, 'arr' => 0, 'fnc' => 0); $escape = false;
        $end = strlen($input); $chars = str_split($input);
        for ($pos = 0; $pos <= $end; $pos++) {
            $char = $chars[$pos];
            if ($char == "'" && $depth['arr'] == 0 && $depth['fnc'] == 0 && $depth['std'] == 0) {
                if ($depth['sts'] == 0) {
                    $depth['sts']++;
                } elseif (!$escape) {
                    $depth['sts']--;
                }
            } elseif ($char == '"' && $depth['arr'] == 0 && $depth['fnc'] == 0 && $depth['sts'] == 0) {
                if ($depth['std'] == 0) {
                    $depth['std']++;
                } elseif (!$escape) {
                    $depth['std']--;
                }
            } elseif ($char == '[' && $depth['fnc'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $depth['arr']++;
            } elseif ($char == ']' && $depth['fnc'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $depth['arr']--;
            } elseif ($char == '{' && $depth['arr'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $depth['fnc']++;
            } elseif ($char == '}' && $depth['arr'] == 0 && $depth['sts'] == 0 && $depth['std'] == 0) {
                $depth['fnc']--;
            } elseif (!$escape && $char == '\\' && ($depth['sts'] == 1 || $depth['std'] == 1)) {
                $escape = true;
            } else {
                $escape = false;
            }
            $onTop = $depth['sts'] == 0 && $depth['std'] == 0 && $depth['arr'] == 0 && $depth['fnc'] == 0;
            if (($char == ',' && $onTop) || $pos == $end) {
                if (preg_match('/^\$([a-zA-Z_]\w*)=(.+)$/', $buffer, $matches)) {
                    $return[$matches[1]] = $this->evaluateFormula($matches[2]);
                } elseif (preg_match('/^\$([a-zA-Z_]\w*)$/', $buffer, $matches)) {
                    $return[$matches[1]] = null;
                } else {
                    return $this->_triggerMessage('Invalid syntax in assignment list', 2);
                }
                $buffer = '';
            } elseif ($char == ' ' && $onTop) {
                continue;
            } else {
                $buffer .= $char;
            }
        }
        return $return;
    }
    
    private function _triggerMessage($message, $level = 1) {
        if ($level >= 2) {
            $type = 'ERROR';
        } elseif ($level == 1) {
            $type = 'WARNING';
        } else {
            $type = 'NOTICE';
        }
        $message .= ($this->_zone ? ' in '.$this->_zone : '') . ' on line '.$this->_line;
        $this->messages[] = $type.': '.$message;
        if ($type == 'ERROR' && $this->throwErrors)
            throw new Exception('scriptparser: '.$message);
        return false;
    }
    
}

class scriptparser_variable {

    public $value;
    
    public $dataType;
    
    public $scope;
    
    public function __construct($value, $scope = 0) {
        $this->value = $value;
        $this->dataType = gettype($value);
        $this->scope = $scope;
    }
    
}

class scriptparser_function {

    public $name;
    
    public $sequence;
    
    public $arguments = array();
    
    public $argDefaults = array();
    
    public $type = 'none';
    
    public function __construct($name, $sequence, array $arguments, array $argDefaults = array()) {
        $this->name = $name;
        $this->sequence = $sequence;
        $this->arguments = $arguments;
        $this->argDefaults = $argDefaults;
        if (is_callable($sequence)) {
            $this->type = 'callback';
        } elseif (is_string($sequence)) {
            $this->type = 'code';
        } else {
            trigger_error('scriptparser: Invalid $sequence for function', E_USER_WARNING);
        }
    }
    
}

class scriptparser_mathStack {

    public $stack = array();
    
    public $count = 0;
    
    public function push($val) {
        $this->stack[$this->count] = $val;
        $this->count++;
    }
    
    public function pop() {
        if ($this->count > 0) {
            $this->count--;
            return $this->stack[$this->count];
        }
        return null;
    }
    
    public function last($n = 1) {
        return $this->stack[$this->count - $n];
    }
    
}

class scriptparser_blockStack {

    private $_stack = array();
    
    private $_currentLevel = 0;
    
    private $_stackLevel = 0;
    
    public function __construct(&$currentLevel) {
        $this->_currentLevel =& $currentLevel;
        $this->update('main', true);
    }
    
    public function isRegistered() {
        return isSet($this->_stack[$this->_currentLevel]);
    }
    
    public function get($property, $n = 0) {
        $level = $this->_currentLevel - $n;
        if (isSet($this->_stack[$level][$property])) {
            return $this->_stack[$level][$property];
        } else {
            return false;
        }
    }
    
    public function update($type, $active = true, $options = array()) {
        if ($this->_stackLevel < $this->_currentLevel)
            $this->_stackLevel++;
        $info =& $this->_stack[$this->_stackLevel];
        $info['type'] = $type;
        $info['active'] = $active;
        if ($type == 'if' && $active) {
            $info['done'] = true;
        } elseif ($type == 'while' || $type == 'for' || $type == 'foreach') {
            $info['loop'] = true;
            $info['loopBegin'] = $options['loopBegin'];
            $iterate = isSet($options['iterate']) ? (int) $options['iterate'] : 1;
            $info['iteration'] += $iterate;
        } elseif ($type == 'macro') {
            $info['name'] = $options['name'];
            $info['args'] = $options['args'];
            $info['argDefaults'] = $options['argDefaults'];
        }
    }
    
    public function remove() {
        if ($this->_stackLevel > 0) {
            array_pop($this->_stack);
            $this->_stackLevel--;
        }
        return false;
    }
    
}
