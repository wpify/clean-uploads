<?php

namespace WP_CLI\Wpify;

use WP_CLI;
use WP_CLI_Command;

class CleanUploadsCommand extends WP_CLI_Command {
	/**
	 * Cleans the uploads folder from unused files.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp clean-uploads
	 *
	 * @when before_wp_load
	 *
	 * @param array $args Indexed array of positional arguments.
	 * @param array $assoc_args Associative array of associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ) {
		if ( is_multisite() ) {
			$sites = get_sites();

			foreach ( $sites as $site ) {
				/** @var \WP_Site $site */
				WP_CLI::line( "Processing site $site->siteurl..." );

				switch_to_blog( $site->blog_id );

				$this->process_file_removal();

				restore_current_blog();
			}
		} else {
			$this->process_file_removal();
		}

		WP_CLI::success( 'Finished!' );
	}

	private function process_file_removal() {
		$dump       = $this->get_sql_dump();
		$uploads    = $this->find_uploads_folder();
		$file_count = $this->get_file_count( $uploads );
		$progress   = WP_CLI\Utils\make_progress_bar( "Processing files...", $file_count );

		$this->walk_all_files( $uploads, function ( string $file ) use ( $uploads, $dump, $progress ) {
			$progress->tick();

			if ( preg_match( "~[0-9]{4}/[0-9]{2}/(?<filename>[^/]+$)~m", $file, $matches ) ) {
				if ( mb_strpos( $dump, $matches['filename'] ) === false ) {
					WP_CLI::line( "remove " . $file );

					if ( ! unlink( $file ) ) {
						WP_CLI::line( "could not remove " . $file );
					}
				}
			}
		} );

		WP_CLI::line( "Removing empty folders..." );

		$this->remove_empty_folders( $uploads );
	}

	/**
	 * Find uploads folder.
	 *
	 * @return string
	 */
	private function find_uploads_folder(): string {
		$upload_dir = wp_upload_dir();

		return $upload_dir['basedir'];
	}

	/**
	 * Get the SQL dump from the database.
	 *
	 * @return string
	 */
	private function get_sql_dump(): string {
		WP_CLI::line( "Requesting database dump..." );

		$destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . wp_generate_uuid4() . '.sql';

		if ( file_exists( $destination ) ) {
			unlink( $destination );
		}

		global $wpdb;

		// filter tables that belongs only to the current blog
		$tables = array_filter( $wpdb->get_col( 'show tables' ), function ( $table ) use ( $wpdb ) {
			return preg_match( '~^' . $wpdb->prefix . '\D~', $table );
		} );

		WP_CLI::line( join( ' ', $tables ) );

		exec( "mysqldump --user=" . DB_USER . " --password=" . DB_PASSWORD . " --host=" . DB_HOST . " " . DB_NAME . " " . join( ' ', $tables ) . "> " . $destination );

		$dump = file_get_contents( $destination );

		unlink( $destination );

		return $dump;
	}

	/**
	 * Get the file count in folder.
	 *
	 * @param $path
	 *
	 * @return int
	 */
	private function get_file_count( $path ): int {
		$size   = 0;
		$ignore = array( '.', '..' );
		$files  = scandir( $path );

		foreach ( $files as $t ) {
			if ( in_array( $t, $ignore ) ) {
				continue;
			} elseif ( is_dir( rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $t ) ) {
				$size += $this->get_file_count( rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $t );
			} else {
				$size ++;
			}
		}

		return $size;
	}

	/**
	 * Removes empty folders recursively.
	 *
	 * @param $path
	 *
	 * @return bool
	 */
	private function remove_empty_folders( $path ): bool {
		$empty = true;

		foreach ( glob( $path . DIRECTORY_SEPARATOR . '*' ) as $file ) {
			if ( is_dir( $file ) ) {
				if ( ! $this->remove_empty_folders( $file ) ) {
					$empty = false;
				}
			} else {
				$empty = false;
			}
		}

		if ( $empty ) {
			WP_CLI::line( "remove empty folder " . $path );
			@rmdir( $path );
		}

		return $empty;
	}

	/**
	 * Walk through the uploads folder and run callback on each file.
	 *
	 * @param string $dir
	 * @param callable $callback
	 */
	private function walk_all_files( string $dir, callable $callback ): void {
		$root = scandir( $dir );

		foreach ( $root as $value ) {
			if ( $value === '.' || $value === '..' ) {
				continue;
			}

			if ( is_file( $dir . DIRECTORY_SEPARATOR . $value ) ) {
				$callback( $dir . DIRECTORY_SEPARATOR . $value, $dir );

				continue;
			}

			$this->walk_all_files( $dir . DIRECTORY_SEPARATOR . $value, $callback );
		}
	}
}
