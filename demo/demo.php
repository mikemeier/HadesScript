<?php
error_reporting(E_ALL ^ E_NOTICE);
header('Content-Type: text/plain');

include('../scriptparser.class.php');

try {
    $parser = new scriptparser;
    $parser->executeFile('foobar.hds');
} catch (scriptparser_error $error) {
    echo $error->getMessage();
}
?>
