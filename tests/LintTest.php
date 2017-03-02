<?php

class LintTest extends \PHPUnit_Framework_TestCase
{
    public function testSrc()
    {
        // Main entry
        $fileList = \glob('src/');

        // Loop through known files
        while ( $inode = \array_shift($fileList) ) {

            // Try to iterate deeper
            if ( is_dir($inode) ) {
                $fileList = \array_merge($fileList, \glob(\realpath($inode).'/*'));
                continue;
            }

            // If we're a PHP file
            if (\preg_match('/^.+\.(php|inc)$/i', $inode)) {
                // Run a unit test
                $this->lintFile($inode);
            }

        }
    }

    private function lintFile($filename = '')
    {
        // And actually run the test (with proper error message)
        $this->assertContains('No syntax errors', \exec(\sprintf('php -l "%s"', $filename), $out), \sprintf("%s contains syntax errors", $filename));
    }
}
