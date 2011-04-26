<?php
/**
 * Function library for namespace 'string'
 * 
 * @author  Christian Neff <christian.neff@gmail.com>
 */
class scriptlib_string {

    public static function find($find, $string) {
        return strpos($string, $find);
    }
    
    public static function length($string) {
        return strlen($string);
    }
    
    public static function replace($find, $replace, $string) {
        return str_replace($find, $replace, $string);
    }
    
    public static function slice($string, $offset, $length = 0) {
        if ($length == 0) {
            return substr($string, $offset);
        } else {
            return substr($string, $offset, $length);
        }
    }
    
    public static function split($delimiter, $string, $limit = 0) {
        if ($limit == 0) {
            return explode($delimiter, $string);
        } else {
            return explode($delimiter, $string, $limit);
        }
    }
    
}
?>
