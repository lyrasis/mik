<?php

/**
 * Script to generate test files for MIK. Currently only supports PDF files.
 */

require 'vendor/autoload.php';
use League\Csv\Reader;

if (count($argv) == 1) {
    print "Enter 'php " . $argv[0] . " help' to see more info.\n";
    exit;
}

if (trim($argv[1]) == 'help') {
    print "A script to generate test files for MIK. Currently only supports PDF files.\n\n";
    print "Example usage: php create_test_files.php --ini=test.ini\n\n";
    print "Options:\n";
    print "    --ini : The path to the ini configuration file being tested. Required.\n";
    exit;
}

$options = getopt('', array('ini:'));

// Check to see if the specified config file exists and if not, exit.
if (!file_exists($options['ini'])) {
    print "Sorry, " . $options['ini'] . " does not appear to exist.\n";
    exit;
}

// parse config file
$config_path = $options['ini'];
$config = parse_ini_file($config_path, TRUE);
// test files names will be read from the input csv file
$input_file = $config['FETCHER']['input_file'];
// test files will be created in the input directory for subsequent use
$input_directory = $config['FILE_GETTER']['input_directory'];

// check input file
if (!file_exists($input_file)) {
    print "Sorry, " . $input_file. " does not appear to exist.\n";
    exit;
}

// check input directory
if (!file_exists($input_directory)) {
    print "Sorry, " . $input_directory. " does not appear to exist.\n";
    exit;
}

// read from the input file
$reader = Reader::createFromPath($input_file, 'r');
$csv = $reader->fetchAssoc();
foreach ($csv as $record) {
    $file_name = $record['File Name'];
    create_file($input_directory, $file_name);
}

function create_file($input_directory, $file_name) {
    $filepath = $input_directory . "/" . $file_name;
    // don't overwrite existing files
    if (!file_exists($filepath)) {
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial','B',16);
        $pdf->Cell(40,10,$file_name);
        $pdf->Output("F", $filepath);
        print $filepath . " created. \n";
    }
    else {
      print $filepath . " already exists. Skipping. \n";
    }
}
