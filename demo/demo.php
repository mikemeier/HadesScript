<?php
error_reporting(E_ALL ^ E_NOTICE);
header('Content-Type: text/plain');

include('../scriptparser.class.php');

try {
    $parser = new scriptparser;
    $parser->executeFile('foobar.hds');
    print_r($parser->messages);
} catch (Exception $error) {
    echo $error->getMessage();
}
?>
