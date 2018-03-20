<?php
// src/inputvalidators/CsvBooks.php

namespace mik\inputvalidators;

/**
 * MikInputValidator class for the MIK CSV compound object toolchain.
 */
class CsvBooks extends MikInputValidator
{
    /**
     * Create a new MikInputValidator instance.
     *
     * @param array $settings
     *   Associative array containing this toolchain's config settings.
     */
    public function __construct($settings)
    {
        parent::__construct($settings);
        $this->fetcher = new \mik\fetchers\Csv($settings);
        $this->fileGetter = new \mik\filegetters\CsvBooks($settings);

        $this->unwantedFiles = array(
            'Thumbs.db',
            '.Thumbs.db',
            'DS_Store',
            '.DS_Store',
        );

        // Default is to use - as the sequence separator in the page filename.
        // The separator is used here in a regex, so we escape it.
        if (isset($settings['WRITER']['page_sequence_separator'])) {
            $this->page_sequence_separator = $settings['WRITER']['page_sequence_separator'];
        } else {
            $this->page_sequence_separator = '-';
        }
        $this->page_sequence_separator = preg_quote($this->page_sequence_separator);

        $this->ocr_extension = '.txt';
        // Default is to not log the absence of page-level OCR files.
        if (isset($settings['WRITER']['log_missing_ocr_files'])) {
            $this->log_missing_ocr_files = $settings['WRITER']['log_missing_ocr_files'];
        } else {
            $this->log_missing_ocr_files = false;
        }
    }

    /**
     * Wrapper function for validating all input packages.
     *
     * @return array
     *   A list of boolean values, one for each package.
     */
    public function validateAll()
    {
        if (!$this->validateInput) {
            return;
        }

        if ($this->validateInputType == 'realtime') {
            return;
        }

        $validation_results = array();
        $records = $this->fetcher->getRecords();
        $book_directory_field = $this->fileGetter->file_name_field;
        foreach ($records as $record) {
            $book_directory_value = $record->{$book_directory_field};
            $package_path = $this->settings['FILE_GETTER']['input_directory'] .
                DIRECTORY_SEPARATOR . $book_directory_value;
            if (!$validated = $this->validatePackage($record->key, $package_path)) {
                $validation_results[] = false;
            }
        }
        return $validation_results;
    }

    /**
     * Wrapper function for validating a single input package (book).
     *
     * @param $record_key string
     *   The package's record key.
     *
     * @param $package_path string
     *   The package's input directory name (not full path).
     *
     * @return boolean
     *    True if all tests pass for the package, false if any tests failed.
     */
    public function validatePackage($record_key, $package_path)
    {
        // We don't want to revalidate the same compound book object directory
        // more than once, so we keep track of ones we've validated here.
        static $package_path_validated = array();
        if (in_array($package_path, $package_path_validated)) {
            return true;
        }

        $cumulative_validation_results = array();

        // Book directory must exist, and be a directory.
        if (file_exists($package_path) && is_dir($package_path)) {
            $cumulative_validation_results[] = true;

            // It cannot be empty.
            $pages = $this->getPageFiles($package_path);
            if (count($pages) === 0) {
                $this->log->addError(
                    "Input validation failed",
                    array(
                        'record ID' => $record_key,
                        'book object directory' => $package_path,
                        'error' => 'Book object input directory is empty'
                    )
                );
                $cumulative_validation_results[] = false;
            }

            // Book directory cannot contain Thumbs.db, etc.
            $intersection = array_intersect($this->unwantedFiles, $pages);
            if (count($intersection)) {
                $this->log->addError(
                    "Input validation failed",
                    array(
                        'record ID' => $record_key,
                        'book object directory' => $package_path,
                        'error' => 'Book object input directory contains unwanted files'
                    )
                );
                $cumulative_validation_results[] = false;
            }

            // Files in page directory must have one of the allowed extensions.
            if (!$this->checkPageExtensions($pages)) {
                $this->log->addError(
                    "Input validation failed",
                    array(
                        'record ID' => $record_key,
                        'book object directory' => $package_path,
                        'error' => 'Some files in the book object directory have invalid extensions'
                    )
                );
                $cumulative_validation_results[] = false;
            }

            if (!$this->checkOcrFiles($package_path, $pages)) {
                $this->log->addError(
                    "Input validation failed",
                    array(
                        'record ID' => $record_key,
                        'issue directory' => $package_path,
                        'error' => 'Book directory is missing one or more OCR files'
                    )
                );
                $cumulative_validation_results[] = false;
            }

            // Files in book directory must be named such that their last
            // filename segment is numeric.
            if (!$this->checkPageSequenceNumbers($pages)) {
                $this->log->addError(
                    "Input validation failed",
                    array(
                        'record ID' => $record_key,
                        'book object directory' => $package_path,
                        'error' => 'Some files in the book object directory have invalid sequence numbers'
                    )
                );
                $cumulative_validation_results[] = false;
            }
        } else {
            $this->log->addError(
                "Input validation failed",
                array(
                    'record ID' => $record_key,
                    'book object directory' => $package_path,
                    'error' => 'Book object directory not found'
                )
            );
            $cumulative_validation_results[] = false;
        }

        $package_path_validated[] = $package_path;

        if (in_array(false, $cumulative_validation_results)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Gets the filenames of the files in the book-level directory.
     *
     * @param $dir string
     *    The full path to the book-level directory.
     *
     * @return array
     *    A list of all the file names (not just page images).
     */
    private function getPageFiles($dir)
    {
        $page_files = array();
        $files = $this->readDir($dir);
        foreach ($files as &$file) {
            $file = basename($file);
            foreach ($files as $file) {
                $pathinfo = pathinfo($file);
                $page_file = $pathinfo['basename'];
                $page_files[] = $page_file;
            }
        }
        return $page_files;
    }

    /**
     * Validates the extensions of the pages in the book-level directory.
     *
     * @param $files array
     *    A list of all the page file names.Files must have one of
     *    following extensions: tif, tiff, jp2.
     *
     * @return boolean
     *    True if all files have an allowed file extension, false if not.
     */
    private function checkPageExtensions($files)
    {
        $valid = true;
        foreach ($files as $file) {
            $pathinfo = pathinfo($file);
            $ext = $pathinfo['extension'];
            if ($this->log_missing_ocr_files) {
                $ocr_extension = ltrim($this->ocr_extension, '.');
                $allowed_extensions = array_merge(
                    $this->fileGetter->allowed_file_extensions_for_OBJ,
                    array($ocr_extension)
                );
            } else {
                $allowed_extensions = $this->fileGetter->allowed_file_extensions_for_OBJ;
            }
            if (!in_array($ext, $allowed_extensions)) {
                $valid = false;
            }
        }
        return $valid;
    }

    /**
     * Validates the sequence numbers of the pages in the book-level directory.
     *
     * @param $files array
     *    A list of all the page file names.
     *
     * @return boolean
     *    True if all files have valid sequence numbers (e.g., \-\d$ at the end), false if not.
     */
    private function checkPageSequenceNumbers($files)
    {
        $valid = true;
        foreach ($files as $file) {
            if (!in_array($file, $this->unwantedFiles)) {
                $pathinfo = pathinfo($file);
                $filename = $pathinfo['filename'];
                if (!preg_match('/' . $this->page_sequence_separator . '\d+$/', $filename)) {
                    $valid = false;
                }
            }
        }
        return $valid;
    }

    /**
     * Checks for the existence of page-level OCR files.
     *
     * @param $book_directory_path string
     *    The absolute path to the book-level directory.
     * @param $files array
     *    A list of all the page file names in the directory.
     *
     * @return boolean
     *    True if all image files have corresponding OCR files.
     */
    private function checkOcrFiles($book_directory_path, $files)
    {
        $valid = true;
        if (!$this->log_missing_ocr_files) {
            return $valid;
        }
        foreach ($files as $file) {
            $pathinfo = pathinfo($file);
            $filename = $pathinfo['filename'];
            $path_to_ocr_file = realpath($book_directory_path) . DIRECTORY_SEPARATOR .
                $filename . $this->ocr_extension;
            if (!file_exists($path_to_ocr_file)) {
                $valid = false;
            }
        }
        return $valid;
    }
}
