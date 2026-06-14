<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$output = "Debug Start\n";

try {
    $output .= "Loading autoload...\n";
    require 'vendor/autoload.php';
    $output .= "Autoload loaded\n";
    
    $output .= "Loading app...\n";
    $app = require 'bootstrap/app.php';
    $output .= "App loaded\n";
} catch (Throwable $e) {
    $output .= "Exception: " . $e->getMessage() . "\n";
    $output .= "File: " . $e->getFile() . "\n";
    $output .= "Line: " . $e->getLine() . "\n";
    $output .= "Stack trace:\n";
    $output .= $e->getTraceAsString();
}

echo $output;
file_put_contents('debug_output.txt', $output);
?>
