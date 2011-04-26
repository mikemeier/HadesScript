<?php
/**
 * Function library for namespace 'array'
 * 
 * @author  Christian Neff <christian.neff@gmail.com>
 */
class scriptlib_array {

    public static function sum($array) {
        return array_sum($array);
    }
    
    public static function product($array) {
        return array_product($array);
    }
    
    public static function slice($array, $offset, $length = 0) {
        if ($length == 0) {
            return array_slice($array, $offset);
        } else {
            return array_slice($array, $offset, $length);
        }
    }
    
    public static function join($delimiter, $array) {
        return implode($delimiter, $array);
    }
    
    public static function length($array, $recursive = false) {
        if ($recursive) {
            return count($array, COUNT_RECURSIVE);
        } else {
            return count($array);
        }
    }
    
}
?>
