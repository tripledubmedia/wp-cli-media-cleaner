<?php
/**
 * Plugin Name:       Media Cleaner CLI
 * Description:       A WP-CLI command to find and clean up unused media and media with broken file links. This plugin was developed with the assistance of Gemini.
 * Version:           1.0.3
 * Author:            Triple Dub
 * Author URI:        https://tripledub.media
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-cleaner-cli
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Don't run this file if WP-CLI is not running.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Implements the 'media-cleaner' command for WP-CLI.
 */
class Media_Cleaner_Command {

    // --- Properties ---

    /**
     * The base path for our data directories, inside wp-content/uploads.
     * @var string
     */
    private $data_dir = '';

    /**
     * The path to the cache directory.
     * @var string
     */
    private $cache_dir = '';

    /**
     * The path to the logs directory.
     * @var string
     */
    private $logs_dir = '';

    /**
     * The file path for the current CSV log file.
     * @var string
     */
    private $log_file = '';

    /**
     * The file handle for the CSV log file.
     * @var resource
     */
    private $log_file_handle;

    /**
     * The associative array of command-line arguments.
     * @var array
     */
    private $assoc_args = [];

    /**
     * Array of search location choices selected by the user.
     * @var array
     */
    private $search_locations = [];

    // --- Constants for Search Locations ---
    const LOCATIONS = [
        1 => ['key' => 'content', 'label' => 'Post & Page Content (the main editor)'],
        2 => ['key' => 'thumbnail', 'label' => 'Featured Images (Post Thumbnails)'],
        3 => ['key' => 'meta', 'label' => 'Custom Fields (includes ACF, Carbon Fields, etc.)'],
        4 => ['key' => 'options', 'label' => 'Theme & Plugin Options (e.g., logos in the Customizer)'],
        5 => ['key' => 'widgets', 'label' => 'Widgets (content within sidebar widgets)'],
        6 => ['key' => 'page_builders', 'label' => 'Page Builder Data (JSON/serialized data)'],
        7 => ['key' => 'woocommerce', 'label' => 'WooCommerce Galleries'],
    ];


    /**
     * Cleans up unused media attachments.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Perform a scan without deleting any files. Creates a cache file for a faster subsequent run.
     *
     * [--skip-cache]
     * : Force a new scan even if a recent cache file exists.
     *
     * ## EXAMPLES
     *
     * wp media-cleaner cleanup
     * wp media-cleaner cleanup --dry-run
     *
     * @when after_wp_load
     */
    public function cleanup( $args, $assoc_args ) {
        $this->assoc_args = $assoc_args;

        if ( ! $this->setup_data_directories() ) {
            // Error message is handled within the method.
            return;
        }

        WP_CLI::line( WP_CLI::colorize( '%CWelcome to the Media Cleaner!%n' ) );

        $is_dry_run = isset( $this->assoc_args['dry-run'] );
        
        // --- Cache Handling ---
        $latest_cache_file = $this->find_latest_cache_file();
        if ( ! $is_dry_run && ! isset($this->assoc_args['skip-cache']) && $latest_cache_file ) {
            $cache_data = json_decode( file_get_contents( $latest_cache_file ), true );
            $cache_time = filemtime( $latest_cache_file );
            $time_ago = human_time_diff($cache_time, time()) . ' ago';
            
            $total_to_delete = count($cache_data['unused_media']) + count($cache_data['broken_link_media']);

            WP_CLI::line( "\nFound a cache file from {$time_ago} (" . date('Y-m-d H:i:s', $cache_time) . ")." );
            WP_CLI::line( "The file contains " . count($cache_data['unused_media']) . " attachments marked as 'Unused' and " . count($cache_data['broken_link_media']) . " marked as 'Broken Link'." );
            
            WP_CLI::confirm( "\nDo you want to use this cache file to proceed with deletion?\nUsing a cache file is much faster than running a new scan.", $this->assoc_args );
            
            $this->perform_deletion( $cache_data );
            return;
        }

        // --- Interactive Location Selection ---
        if ( ! $this->prompt_for_search_locations() ) {
            return; // User aborted
        }
        
        // --- Proceed with Scan ---
        $this->perform_scan( $is_dry_run );
    }

    /**
     * Sets up the necessary data directories within wp-content/uploads.
     *
     * @return bool True on success, false on failure.
     */
    private function setup_data_directories() {
        $upload_dir = wp_upload_dir();
        $this->data_dir = $upload_dir['basedir'] . '/media-cleaner-data';
        $this->cache_dir = $this->data_dir . '/cache';
        $this->logs_dir = $this->data_dir . '/logs';

        $dirs = [ $this->data_dir, $this->cache_dir, $this->logs_dir ];

        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                if ( ! wp_mkdir_p( $dir ) ) {
                    WP_CLI::error( "Could not create directory: {$dir}" );
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Prompts the user to select which locations to scan for media usage.
     *
     * @return bool True if locations were selected, false if aborted.
     */
    private function prompt_for_search_locations() {
        WP_CLI::line( "\nBefore we scan, please specify where we should look for media usage." );
        WP_CLI::line( "This helps to ensure we don't accidentally mark a file as 'unused'." );
        
        WP_CLI::line( "\n--- Search Locations Legend ---" );
        foreach( self::LOCATIONS as $num => $details ) {
            WP_CLI::line( "[$num] {$details['label']}" );
        }
        WP_CLI::line( "-------------------------------" );

        $question = "Indicate the locations to search, separated by commas (e.g., 1,2,3,6):";
        
        // Force flush the output buffer before readline
        fflush(STDOUT);

        while(true) {
            $input = readline($question . " ");
            $input = trim($input);

            if (empty($input)) {
                WP_CLI::warning("Please enter at least one number.");
                continue;
            }

            $choices = array_map('trim', explode(',', $input));
            $valid_choices = [];
            $invalid_found = false;

            foreach($choices as $choice) {
                if (is_numeric($choice) && isset(self::LOCATIONS[intval($choice)])) {
                    $valid_choices[] = intval($choice);
                } else {
                    WP_CLI::warning("Invalid selection: '{$choice}'. Please use numbers from the legend.");
                    $invalid_found = true;
                    break;
                }
            }

            if (!$invalid_found) {
                $this->search_locations = array_unique($valid_choices);
                $selected_labels = array_map(function($c) { return self::LOCATIONS[$c]['label']; }, $this->search_locations);
                WP_CLI::line("\nExcellent. We will search in: " . implode(', ', $selected_labels));
                return true;
            }
        }
        return false;
    }

    /**
     * Performs the main analysis scan of all media attachments.
     *
     * @param bool $is_dry_run If true, no files will be deleted.
     */
    private function perform_scan( $is_dry_run ) {
        global $wpdb;

        $this->initialize_log_file();
        $this->log_to_csv( ['Attachment ID', 'File Name', 'Upload Folder', 'Status', 'Action Taken', 'Timestamp'] );

        if ( $is_dry_run ) {
            WP_CLI::line( "\nStarting analysis in --dry-run mode. NO files will be deleted." );
        } else {
            WP_CLI::line( "\nStarting analysis. This may take a while." );
        }
        WP_CLI::line( "A log file will be created at: {$this->log_file}" );

        WP_CLI::line( "\nStep 1/2: Finding all media attachments..." );
        $attachment_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
        $total_attachments = count( $attachment_ids );

        if ( $total_attachments === 0 ) {
            WP_CLI::success( "No media attachments found to analyze." );
            return;
        }

        WP_CLI::line( "Found {$total_attachments} media attachments to analyze." );
        WP_CLI::line( "\nStep 2/2: Analyzing media usage..." );
        
        // Force flush the output buffer before the progress bar
        fflush(STDOUT);

        $progress = \WP_CLI\Utils\make_progress_bar( 'Analyzing media', $total_attachments );

        $unused_media = [];
        $broken_link_media = [];
        $kept_media_count = 0;
        
        $upload_dir_info = wp_upload_dir();
        $upload_base_dir = $upload_dir_info['basedir'];

        foreach ( $attachment_ids as $id ) {
            $file_path = get_attached_file( $id );
            $file_name = basename( $file_path );
            $relative_path = $file_path ? str_replace( $upload_base_dir, '', $file_path ) : '';
            $upload_folder = $relative_path ? ltrim( dirname( $relative_path ), '/' ) : '';

            if ( ! $file_path || ! file_exists( $file_path ) ) {
                $broken_link_media[] = $id;
                $action = $is_dry_run ? 'Skipped (Dry Run)' : 'To Be Deleted';
                $this->log_to_csv( [$id, $file_name, $upload_folder, 'Broken Link', $action, date('Y-m-d H:i:s')] );
            } else {
                if ( $this->is_media_used( $id, $file_path ) ) {
                    $kept_media_count++;
                    $this->log_to_csv( [$id, $file_name, $upload_folder, 'Used', 'Kept', date('Y-m-d H:i:s')] );
                } else {
                    $unused_media[] = $id;
                    $action = $is_dry_run ? 'Skipped (Dry Run)' : 'To Be Deleted';
                    $this->log_to_csv( [$id, $file_name, $upload_folder, 'Unused', 'action', date('Y-m-d H:i:s')] );
                }
            }
            $progress->tick();
        }
        $progress->finish();

        $cache_file_path = $this->cache_dir . '/media-cleaner-cache-' . date('Y-m-d-His') . '.json';
        $cache_data = [
            'unused_media' => $unused_media,
            'broken_link_media' => $broken_link_media
        ];
        file_put_contents( $cache_file_path, json_encode( $cache_data, JSON_PRETTY_PRINT ) );
        
        WP_CLI::line( "\n-------------------------------" );
        WP_CLI::success( $is_dry_run ? "Dry Run Complete!" : "Scan Complete!" );
        WP_CLI::line( "-------------------------------" );
        WP_CLI::line( "📊 Summary:" );
        WP_CLI::line( "- Total Media Analyzed: {$total_attachments}" );
        WP_CLI::line( "- Media Kept (In Use): {$kept_media_count}" );
        WP_CLI::line( "- Found Unused: " . count($unused_media) );
        WP_CLI::line( "- Found with Broken File Links: " . count($broken_link_media) );
        WP_CLI::line( "\nA cache file has been created at:\n{$cache_file_path}" );

        if ( $is_dry_run ) {
            WP_CLI::line( "\nTo delete these files, run `wp media-cleaner cleanup` and confirm you want to use this cache file." );
        } else {
            $total_to_delete = count($unused_media) + count($broken_link_media);
            if ($total_to_delete > 0) {
                 WP_CLI::confirm( "\nReady to delete {$total_to_delete} attachments. This is irreversible. Proceed?", $this->assoc_args );
                 $this->perform_deletion( $cache_data );
            } else {
                WP_CLI::success("\nNo unused or broken media found to delete.");
            }
        }
        
        $this->close_log_file();
    }

    /**
     * Deletes attachments based on data from a cache file.
     *
     * @param array $cache_data The decoded JSON data from the cache file.
     */
    private function perform_deletion( $cache_data ) {
        $ids_to_delete = array_merge( $cache_data['unused_media'], $cache_data['broken_link_media'] );
        $ids_to_delete = array_unique( array_filter( $ids_to_delete, 'is_numeric' ) );

        $total_to_delete = count($ids_to_delete);
        if ( $total_to_delete === 0 ) {
            WP_CLI::success( "No items found in cache file to delete." );
            return;
        }

        WP_CLI::warning( "You are about to permanently delete {$total_to_delete} media attachments from the media library and the server.\nThis action is IRREVERSIBLE." );
        WP_CLI::confirm( "Are you absolutely sure you want to continue?", $this->assoc_args );

        WP_CLI::line("\nProceeding with deletion...");
        
        if(!$this->log_file) {
            $this->initialize_log_file();
            $this->log_to_csv( ['Attachment ID', 'File Name', 'Upload Folder', 'Status', 'Action Taken', 'Timestamp'] );
        }
        
        $progress = \WP_CLI\Utils\make_progress_bar( 'Deleting media', $total_to_delete );
        $deleted_count = 0;
        
        $upload_dir_info = wp_upload_dir();
        $upload_base_dir = $upload_dir_info['basedir'];

        foreach ( $ids_to_delete as $id ) {
            $file_path = get_attached_file( $id );
            $file_name = basename( $file_path );
            $is_broken = in_array($id, $cache_data['broken_link_media']);
            $relative_path = $file_path ? str_replace( $upload_base_dir, '', $file_path ) : '';
            $upload_folder = $relative_path ? ltrim( dirname( $relative_path ), '/' ) : '';
            
            if ( wp_delete_attachment( $id, true ) ) {
                $deleted_count++;
                $status = $is_broken ? 'Broken Link' : 'Unused';
                $this->log_to_csv([$id, $file_name, $upload_folder, $status, 'Deleted', date('Y-m-d H:i:s')]);
            } else {
                 $this->log_to_csv([$id, $file_name, $upload_folder, 'Error', 'Deletion Failed', date('Y-m-d H:i:s')]);
                 WP_CLI::warning("Failed to delete attachment ID: {$id}");
            }
            $progress->tick();
        }
        $progress->finish();
        
        $this->close_log_file();
        
        WP_CLI::line( "\n-------------------------------" );
        WP_CLI::success( "Deletion Complete!" );
        WP_CLI::line( "-------------------------------" );
        WP_CLI::line( "📊 Summary:" );
        WP_CLI::line( "- Attachments Deleted: {$deleted_count}" );
        WP_CLI::line( "\nA detailed log of all actions taken has been saved to:\n{$this->log_file}" );
    }

    /**
     * Checks if a media item is used in any of the selected locations.
     *
     * @param int $attachment_id The ID of the attachment to check.
     * @param string $file_path The absolute path to the attachment's file.
     * @return bool True if used, false if not.
     */
    private function is_media_used( $attachment_id, $file_path ) {
        global $wpdb;

        $attachment_url = wp_get_attachment_url( $attachment_id );
        $url_parts = parse_url($attachment_url);
        $url_path_fragment = ltrim($url_parts['path'], '/');

        $file_name = basename( $file_path );

        foreach ($this->search_locations as $location_id) {
            $location_key = self::LOCATIONS[$location_id]['key'];
            
            switch($location_key) {
                case 'content':
                case 'page_builders':
                    $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_content LIKE %s", '%' . $wpdb->esc_like( $file_name ) . '%', '%' . $wpdb->esc_like( $url_path_fragment ) . '%' );
                    if ( $wpdb->get_var( $sql ) > 0 ) return true;
                    break;

                case 'thumbnail':
                    $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s", $attachment_id );
                    if ( $wpdb->get_var( $sql ) > 0 ) return true;
                    break;
                
                case 'meta':
                    $sql = $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE (meta_value = %s) OR (meta_value LIKE %s) OR (meta_value LIKE %s)",
                        $attachment_id,
                        '%"' . $wpdb->esc_like( $attachment_id ) . '"%',
                        '%' . $wpdb->esc_like( $file_name ) . '%'
                    );
                    if ( $wpdb->get_var( $sql ) > 0 ) return true;
                    break;
                
                case 'options':
                case 'widgets':
                     $sql = $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_value LIKE %s) OR (option_value LIKE %s)",
                        '%"' . $wpdb->esc_like( $attachment_id ) . '"%',
                        '%' . $wpdb->esc_like( $file_name ) . '%'
                    );
                    if ( $wpdb->get_var( $sql ) > 0 ) return true;
                    break;
                
                case 'woocommerce':
                    $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value LIKE %s", '%' . $wpdb->esc_like( $attachment_id ) . '%' );
                    if ( $wpdb->get_var( $sql ) > 0 ) return true;
                    break;
            }
        }
        
        return false;
    }

    // --- File and Logging Helpers ---

    private function initialize_log_file() {
        $this->log_file = $this->logs_dir . '/media-cleaner-log-' . date('Y-m-d-His') . '.csv';
        $this->log_file_handle = fopen( $this->log_file, 'w' );
        if ( ! $this->log_file_handle ) {
            WP_CLI::error( "Could not open log file for writing: {$this->log_file}" );
        }
    }

    private function log_to_csv( $row ) {
        if ( $this->log_file_handle ) {
            fputcsv( $this->log_file_handle, $row );
        }
    }

    private function close_log_file() {
        if ( $this->log_file_handle ) {
            fclose( $this->log_file_handle );
            $this->log_file_handle = null;
        }
    }

    private function find_latest_cache_file() {
        $files = glob( $this->cache_dir . '/media-cleaner-cache-*.json' );
        if ( ! $files ) {
            return null;
        }
        usort( $files, function ( $a, $b ) {
            return filemtime( $b ) - filemtime( $a );
        } );
        return $files[0];
    }
}

WP_CLI::add_command( 'media-cleaner', 'Media_Cleaner_Command' );