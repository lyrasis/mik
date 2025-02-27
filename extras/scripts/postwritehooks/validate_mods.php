<?php

/**
 * Post-write hook script for MIK that validates MODS XML files.
 * Works for single-file CONTENTdm and CSV import packages as well as
 * newspaper issue packages, and can be extended to handle the MODS.xml
 * files created by other MIK toolchains.
 */

require 'vendor/autoload.php';

// Relative to MIK, not this script.
$path_to_schema = 'extras/LoC/mods-3-6.xsd';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use GuzzleHttp\Client;

$record_key = trim($argv[1]);
$children_record_keys = explode(',', $argv[2]);
$config_path = trim($argv[3]);
$config = parse_ini_file($config_path, true);

$mods_filename = 'MODS.xml';
// The CONTENTdm 'nick' for the field that contains the data used
// to create the issue-level output directories.
$item_info_field_for_issues = 'date';

$path_to_success_log = $config['WRITER']['output_directory'] . DIRECTORY_SEPARATOR .
    'postwritehook_validate_mods_success.log';
$path_to_error_log = $config['WRITER']['output_directory'] . DIRECTORY_SEPARATOR .
    'postwritehook_validate_mods_error.log';

// Set up logging.
$info_log = new Logger('postwritehooks/validate_mods.php');
$info_handler = new StreamHandler($path_to_success_log, Logger::INFO);
$info_log->pushHandler($info_handler);

$error_log = new Logger('postwritehooks/validate_mods.php');
$error_handler = new StreamHandler($path_to_error_log, Logger::WARNING);
$error_log->pushHandler($error_handler);

// Different MIK writers will put the MODS files in different places. We need
// to determine that type of writer is being used and hand off the task of finding
// and validating the MODS files to the appropriate callback.
switch ($config['WRITER']['class']) {
  case 'CdmNewspapers':
    cdm_newspapers_writer($record_key, $children_record_keys, $path_to_schema, $mods_filename, $item_info_field_for_issues, $config, $info_log, $error_log);
    break;
  case 'CsvSingleFile':
    csv_single_file_writer($record_key, $path_to_schema, $config, $info_log, $error_log);
    break;
  default:
    cdm_single_file_writer($record_key, $path_to_schema, $config, $info_log, $error_log);
    break;
}

/**
 * Callback to validate the MODS file for each CONTENTdm single-file object.
 *
 * @param string $record_key
 *   The value of the record key (pointer) for the current newspaper parent object.
 *
 * @param string $path_to_schema
 *   The path to the MODS schema file.
 *
 * @param array $config
 *   The MIK configuration settings.
 *
 * @param object $info_log
 *   A Monolog logger object.
 *
 * @param object $error_log
 *   A Monolog logger object.
 */
function cdm_single_file_writer($record_key, $path_to_schema, $config, $info_log, $error_log) {
  $path_to_mods = $config['WRITER']['output_directory'] . DIRECTORY_SEPARATOR .
    $record_key . '.xml';
  validate_mods($path_to_schema, $path_to_mods, $info_log, $error_log);
}

/**
 * Callback to iterate through a newspaper issue directory and validate all
 * the MODS files therein.
 *
 * @param string $record_key
 *   The value of the record key (pointer) for the current newspaper parent object.
 *
 * @param array $children_record_keys
 *   A list of all the newspaper issue's page pointers.
 *
 * @param string $path_to_schema
 *   The path to the MODS schema file.
 *
 * @param string $mods_filename
 *   The name of the XML file containing the MODS data, including extension.
 *
 * @param string $item_info_field_for_issues
 *   The CONTENTdm nick for the field that contains the string used
 *   to create the issue-level directories in the MIK output.
 *
 * @param array $config
 *   The MIK configuration settings.
 *
 * @param object $log
 *   A Monolog logger object.
 *
 * @param object $error_log
 *   A Monolog logger object.
 */
function cdm_newspapers_writer($record_key, $children_record_keys, $path_to_schema, $mods_filename, $item_info_field_for_issues, $config, $info_log, $error_log) {
  $issue_dir = get_issue_dir($record_key, $item_info_field_for_issues, $config);
  $dir = $config['WRITER']['output_directory'] . DIRECTORY_SEPARATOR . $issue_dir;
  $directory_iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
  foreach ($directory_iterator as $filepath => $info) {
    if (preg_match('/' . $mods_filename . "$/", $filepath)) { 
      validate_mods($path_to_schema, $filepath, $info_log, $error_log);
    }
  }
}

/**
 * Callback to validate the MODS file for each CSV single-file object.
 *
 * @param string $record_key
 *   The value of the record key (pointer) for the current newspaper parent object.
 *
 * @param string $path_to_schema
 *   The path to the MODS schema file.
 *
 * @param array $config
 *   The MIK configuration settings.
 *
 * @param object $log
 *   A Monolog logger object.
 *
 * @param object $error_log
 *   A Monolog logger object.
 */
function csv_single_file_writer($record_key, $path_to_schema, $config, $info_log, $error_log) {
  if (isset($config['WRITER']['preserve_content_filenames']) && $config['WRITER']['preserve_content_filenames']) {
    // Get the value of [FILE_GETTER] file_name_field from the cached metadata
    // and use it, minus the extension, as the MODS filename.
    $file_name_field = $config['FILE_GETTER']['file_name_field'];
    $raw_metadata_cache_path = $config['FETCHER']['temp_directory'] . DIRECTORY_SEPARATOR .
      $record_key . '.metadata';
    $raw_metadata_cache = file_get_contents($raw_metadata_cache_path);
    $metadata = unserialize($raw_metadata_cache);
    $filename = pathinfo($metadata->{$file_name_field}, PATHINFO_FILENAME);
    $path_to_mods = $config['WRITER']['output_directory'] . DIRECTORY_SEPARATOR .
      $filename . '.xml';
    validate_mods($path_to_schema, $path_to_mods, $info_log, $error_log);
  }
  else {
    // drop extension from record_key, so we get the accompanying MODS file
    $record_key = pathinfo($record_key, PATHINFO_FILENAME);
    $path_to_mods = $config['WRITER']['output_directory'] . DIRECTORY_SEPARATOR .
      $record_key . '.xml';
    validate_mods($path_to_schema, $path_to_mods, $info_log, $error_log);
  }
}

/**
 * Validates the specified MODS XML file against the schema and logs
 * the result.
 *
 * @param string $path_to_schema
 *   The path to the MODS schema file.
 *
 * @param string $path_to_mods
 *   The path to the MODS file to be validated.
 *
 * @param object $log
 *   A Monolog logger object.
 *
 * @param object $error_log
 *   A Monolog logger object.
 */
function validate_mods($path_to_schema, $path_to_mods, $info_log, $error_log) {
  $mods = new DOMDocument();
  $mods->load($path_to_mods);
  if ($mods->schemaValidate($path_to_schema)) {
    $info_log->addInfo("MODS file validates", array('MODS file' => $path_to_mods));
  }
  else {
    $error_log->addWarning("MODS file does not validate", array('MODS file' => $path_to_mods));
  }
}

/**
 * Get the string identifying the newspaper issue-level directory where the
 * page-level subdirectories are within the output directory for
 * newspapers.
 *
 * @param string $record_key
 *   The CONTENTdm object's pointer.
 *
 * @param string $item_info_field_for_issues
 *   The CONTENTdm nick for the field that contains the string used
 *   to create the issue-level directories in the MIK output.
 *
 * @param array $config
 *   The MIK configuration settings.
 *
 * @return string|bool
 *   The value of the CONTENTdm field specified in $item_info_field_for_issues,
 *   or false if the field is not populated for this object.
 */
function get_issue_dir($record_key, $item_info_field_for_issues, $config) {
  // Use Guzzle to fetch the output of the call to GetParent
  // for the current object.
  $url = $config['METADATA_PARSER']['ws_url'] .
    'dmGetItemInfo/' . $config['METADATA_PARSER']['alias'] . '/' . $record_key. '/json';
  $client = new Client();
  try {
    $response = $client->get($url);
  } catch (Exception $e) {
    $this->log->addInfo("CdmNoParent",
      array('HTTP request error' => $e->getMessage()));
    return false;
  }
  $body = $response->getBody();
  $item_info = json_decode($body, true);

  if (is_string($item_info_field_for_issues) && strlen($item_info[$item_info_field_for_issues])) {
    return $item_info[$item_info_field_for_issues];
  }
  else {
    return false;
  }
}
