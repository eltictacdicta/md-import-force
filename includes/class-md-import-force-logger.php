<?php

class MD_Import_Force_Logger {

    public static function log_message( $message ) {
        $log_file = __DIR__ . '/../logs/md-import-force.log';
        $timestamp = date( 'Y-m-d H:i:s' );
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;

        // Ensure the logs directory exists
        $log_dir = dirname( $log_file );
        if ( ! file_exists( $log_dir ) ) {
            mkdir( $log_dir, 0755, true );
        }

        file_put_contents( $log_file, $log_entry, FILE_APPEND );
    }
}
