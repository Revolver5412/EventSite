<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Query_Filters_Indexer {
	const INDEXER_OPTION_KEY = 'bricks_indexer_running';
	/**
	 * The one and only Query_Filters_Indexer instance
	 *
	 * @var Query_Filters_Indexer
	 */
	private static $instance = null;
	private $query_filters;

	public function __construct() {
		$this->query_filters = Query_Filters::get_instance();

		if ( Helpers::enabled_query_filters() ) {
			// Register hooks
			$this->register_hooks();
		}
	}

	/**
	 * Singleton - Get the instance of this class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new Query_Filters_Indexer();
		}

		return self::$instance;
	}

	// Register hooks
	public function register_hooks() {
		// A new cron interval every 5 minutes
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );

		// Schedule the cron job
		if ( ! wp_next_scheduled( 'bricks_indexer' ) ) {
			wp_schedule_event( time(), 'brx_every_five_minutes', 'bricks_indexer' );
		}

		// Index query filters every 5 minutes
		add_action( 'bricks_indexer', [ $this, 'continue_index_jobs' ] );

		// Add a new job for an element
		add_action( 'wp_ajax_bricks_background_index_job', [ $this, 'background_index_job' ] );
	}

	/**
	 * Add a new cron interval every 5 minutes
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['brx_every_five_minutes'] = [
			'interval' => 300,
			'display'  => __( 'Every 5 minutes', 'bricks' )
		];

		return $schedules;
	}

	/**
	 * Check if the indexer is running
	 */
	public function indexer_is_running() {
		return get_option( self::INDEXER_OPTION_KEY, false );
	}

	/**
	 * Update the indexer status
	 */
	private function update_indexer_status( $running = false ) {
		update_option( self::INDEXER_OPTION_KEY, (bool) $running, false );
	}

	/**
	 * Retrieve jobs from the database, and continue indexing them
	 * Should be run every 5 minutes, might be triggered manually via do_action( 'bricks_indexer' )
	 * Will not do anything if the indexer is already running to avoid multiple indexer and incorrect indexing
	 */
	public function continue_index_jobs() {
		if ( $this->indexer_is_running() ) {
			// Indexer is running, do nothing
			return;
		}

		// Set the indexer status to running
		$this->update_indexer_status( true );

		while ( $job = $this->get_next_job() ) {
			// Check if server resource limits are reached
			if ( self::resource_limit_reached() ) {
				break;
			}

			// Index the job
			$this->execute_job( $job );
		} // End while

		// Set the indexer status to false, to be triggered again
		$this->update_indexer_status( false );
	}

	/**
	 * Trigger bricks_indexer action
	 * Should be called via wp_remote_post
	 */
	public function background_index_job() {
		Ajax::verify_nonce( 'bricks-nonce-indexer' );
		do_action( 'bricks_indexer' );
		wp_die();
	}

	/**
	 * Execute a job
	 *
	 * @param array $job
	 */
	private function execute_job( $job ) {
		// Get the latest element settings
		$filter_settings = $job['settings'] ?? false;
		$job_settings    = $job['job_details'] ?? false;
		$filter_id       = $job['filter_id'] ?? false;
		$filter_status   = $job['status'] ?? false;

		if ( ! $filter_id || ! $filter_settings || ! $job_settings ) {
			return;
		}

		// If the filter status is not active, remove the job
		if ( ! $filter_status ) {
			// Remove the job
			$this->remove_job( $job );
			// Remove indexed records for this element
			$this->query_filters->remove_index_rows(
				[
					'filter_id' => $filter_id,
				]
			);
			return;
		}

		// If the settings not same, remove the job and add a new job
		if ( $filter_settings !== $job_settings ) {
			// Something not right, need to remove the job, remove indexed records for this element and add a new job
			$this->remove_job( $job );
			// Remove indexed records for this element
			$this->query_filters->remove_index_rows(
				[
					'filter_id' => $filter_id,
				]
			);
			// Add a new job
			$this->add_job( $job, true );
			return;
		}

		$filter_settings = json_decode( $filter_settings, true );
		$filter_source   = $filter_settings['filterSource'] ?? false;

		if ( ! $filter_source ) {
			return;
		}

		// Validate job settings
		if ( ! self::validate_job_settings( $filter_source, $filter_settings ) ) {
			// Invalid job settings, remove the job
			$this->remove_job( $job );
			// Remove indexed records for this element
			$this->query_filters->remove_index_rows(
				[
					'filter_id' => $filter_id,
				]
			);
			return;
		}

		// Get index args
		$args = self::get_index_args( $filter_source, $filter_settings );

		$total          = $job['total'] ?? 0;
		$processing_row = $job['processed'] ?? 0;

		// STEP: Start the index process, each time index 100 posts and update the job
		while ( $processing_row < $total ) {
			if ( self::resource_limit_reached() ) {
				// Resource limits reached, stop indexing, update the processing row and exit
				$this->update_job( $job, [ 'processed' => $processing_row ] );
				break;
			}

			// Get 100 posts
			$args['posts_per_page'] = 100;
			$args['offset']         = $processing_row;
			if ( $filter_source === 'wpField' ) {
				// We need the whole post object
				unset( $args['fields'] );
			}
			$query = new \WP_Query( $args );
			$posts = $query->posts;

			// Index the posts
			foreach ( $posts as $post ) {
				if ( self::resource_limit_reached() ) {
					// Resource limits reached, stop indexing, update the processing row and exit
					$this->update_job( $job, [ 'processed' => $processing_row ] );
					break;
				}

				// Index the post
				$this->index_post_by_job( $post, $job );

				$processing_row++;
			}

			// Update the job
			$this->update_job( $job, [ 'processed' => $processing_row ] );

			// Release memory
			unset( $query );
			unset( $posts );
		}

		// STEP: Job completed
		if ( $processing_row >= $total ) {
			$this->complete_job( $job );
		}
	}

	/**
	 * Validate job settings
	 */
	private static function validate_job_settings( $filter_source, $filter_settings ) {
		switch ( $filter_source ) {
			case 'wpField':
				$field_type = $filter_settings['sourceFieldType'] ?? 'post';

				if ( ! $field_type ) {
					return false;
				}

				$selected_field = false;
				switch ( $field_type ) {
					case 'post':
						$selected_field = $filter_settings['wpPostField'] ?? false;
						break;

					case 'user':
						// not implemented
						break;

					case 'term':
						// not implemented
						break;
				}

				if ( ! $selected_field ) {
					return false;
				}

				break;

			case 'customField':
				$meta_key = $filter_settings['customFieldKey'] ?? false;

				if ( ! $meta_key ) {
					return false;
				}

				break;

			case 'taxonomy':
				$filter_taxonomy = $filter_settings['filterTaxonomy'] ?? false;

				if ( ! $filter_taxonomy ) {
					return false;
				}

				break;

			default:
			case 'unknown':
				return false;

				break;
		}

		return true;
	}

	/**
	 * Get index args for the job
	 */
	private static function get_index_args( $filter_source, $filter_settings ) {
		// NOTE: 'exclude_from_search' => false not in use as we might miss some post types which are excluded from search
		$post_types = get_post_types();

		$args = [
			'post_type'        => $post_types,
			'post_status'      => 'any', // cannot use 'publish' as we might miss some posts
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'cache_results'    => false,
			'no_found_rows'    => true,
			'suppress_filters' => true, // Avoid filters (@since 1.9.10)
			'lang'             => '', // Polylang (@since 1.9.10)
		];

		switch ( $filter_source ) {
			case 'wpField':
				$field_type = $filter_settings['sourceFieldType'] ?? 'post';

				if ( ! $field_type ) {
					return;
				}

				$selected_field = false;
				switch ( $field_type ) {
					case 'post':
						$selected_field = $filter_settings['wpPostField'] ?? false;
						break;

					case 'user':
						// not implemented
						break;

					case 'term':
						// not implemented
						break;
				}

				if ( ! $selected_field ) {
					return;
				}

				// No need to add any query for wpField, all posts will be indexed

				break;

			case 'customField':
				$meta_key = $filter_settings['customFieldKey'] ?? false;

				if ( ! $meta_key ) {
					return;
				}

				// Add meta query
				$args['meta_query'] = [
					[
						'key'     => $meta_key,
						'compare' => 'EXISTS'
					],
				];

				break;

			default:
			case 'taxonomy':
				$filter_taxonomy = $filter_settings['filterTaxonomy'] ?? false;

				if ( ! $filter_taxonomy ) {
					return;
				}

				// Add taxonomy query
				$args['tax_query'] = [
					[
						'taxonomy' => $filter_taxonomy,
						'operator' => 'EXISTS'
					],
				];
				break;
		}

		return $args;
	}

	/**
	 * Generate index rows for a post based on a job (a filter element)
	 * $post: post id || post object (wpField source only)
	 */
	private function index_post_by_job( $post, $job ) {
		$filter_settings = $job['settings'] ?? false;
		$job_settings    = $job['job_details'] ?? false;
		$filter_id       = $job['filter_id'] ?? false;
		if ( ! $filter_id || ! $filter_settings || ! $job_settings ) {
			return;
		}

		$filter_settings = json_decode( $filter_settings, true );
		$filter_source   = $filter_settings['filterSource'] ?? false;

		if ( ! $filter_source ) {
			return;
		}

		$rows_to_insert = [];

		switch ( $filter_source ) {
			case 'wpField':
				$field_type = $filter_settings['sourceFieldType'] ?? 'post';

				if ( ! $field_type ) {
					return;
				}

				$selected_field = false;
				switch ( $field_type ) {
					case 'post':
						$selected_field = $filter_settings['wpPostField'] ?? false;
						break;

					case 'user':
						// not implemented
						break;

					case 'term':
						// not implemented
						break;
				}

				if ( ! $selected_field ) {
					return;
				}

				$post_rows = $this->generate_wp_field_index_row( $post, $selected_field );

				// Build $rows_to_insert, insert filter_id
				if ( ! empty( $post_rows ) ) {
					$rows_to_insert = array_merge(
						$rows_to_insert,
						array_map(
							function( $row ) use ( $filter_id ) {
								$row['filter_id'] = $filter_id;
								return $row;
							},
							$post_rows
						)
					);
				}

				break;

			case 'customField':
				$meta_key = $filter_settings['customFieldKey'] ?? false;

				if ( ! $meta_key ) {
					return;
				}

				$meta_rows = $this->generate_meta_index_row( $post, $meta_key );

				// Build $rows_to_insert, insert filter_id
				if ( ! empty( $meta_rows ) ) {
					$rows_to_insert = array_merge(
						$rows_to_insert,
						array_map(
							function( $row ) use ( $filter_id ) {
								$row['filter_id'] = $filter_id;
								return $row;
							},
							$meta_rows
						)
					);
				}

				break;

			default:
			case 'taxonomy':
				$taxonomy = $filter_settings['filterTaxonomy'] ?? false;

				if ( ! $taxonomy ) {
					return;
				}

				$tax_rows = $this->generate_taxonomy_index_row( $post, $taxonomy );

				// Build $rows_to_insert, insert filter_id
				if ( ! empty( $tax_rows ) ) {
					$rows_to_insert = array_merge(
						$rows_to_insert,
						array_map(
							function( $row ) use ( $filter_id ) {
								$row['filter_id'] = $filter_id;
								return $row;
							},
							$tax_rows
						)
					);
				}
				break;
		}

		if ( empty( $rows_to_insert ) ) {
			return;
		}

		// Insert rows
		$this->query_filters::insert_index_rows( $rows_to_insert );
	}

	/**
	 * Generate taxonomy index rows
	 */
	private function generate_taxonomy_index_row( $post_id, $taxonomy ) {
		$rows = [];

		$terms = get_the_terms( $post_id, $taxonomy );
		// If no terms, skip
		if ( ! $terms || is_wp_error( $terms ) ) {
			return $rows;
		}

		foreach ( $terms as $term ) {
			// Populate rows
			$rows[] = [
				'filter_id'            => '',
				'object_id'            => $post_id,
				'object_type'          => 'post',
				'filter_value'         => $term->slug,
				'filter_value_display' => $term->name,
				'filter_value_id'      => $term->term_id,
				'filter_value_parent'  => $term->parent ?? 0,
			];
		}

		return $rows;
	}

	/**
	 * Generate meta index rows
	 */
	private function generate_meta_index_row( $post_id, $meta_key ) {
		$rows       = [];
		$meta_value = get_post_meta( $post_id, $meta_key, true );

		$rows[] = [
			'filter_id'            => '',
			'object_id'            => $post_id,
			'object_type'          => 'post',
			'filter_value'         => $meta_value,
			'filter_value_display' => $meta_value,
			'filter_value_id'      => 0,
			'filter_value_parent'  => 0,
		];

		return $rows;
	}

	/**
	 * Generate wp field index rows
	 */
	private function generate_wp_field_index_row( $post, $post_field ) {
		$rows = [];

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return $rows;
		}

		$post_id = $post->ID;

		// Change field name if needed so we can get it from post object
		$post_field = $post_field === 'post_id' ? 'ID' : $post_field;

		$value         = $post->$post_field ?? false;
		$display_value = $value ?? 'None';

		// If post field is post_author, get the author name
		if ( $post_field === 'post_author' ) {
			$author        = get_user_by( 'id', $value );
			$display_value = $author->display_name ?? 'None';
		}

		$rows[] = [
			'filter_id'            => '',
			'object_id'            => $post_id,
			'object_type'          => 'post',
			'filter_value'         => $value,
			'filter_value_display' => $display_value,
			'filter_value_id'      => 0,
			'filter_value_parent'  => 0,
		];

		return $rows;
	}

	/**
	 * Add index job for an element
	 * Condition:
	 * - If active job exists, do nothing
	 */
	public function add_job( $element, $remove_active_jobs = false ) {
		$element_id = $element['filter_id'] ?? false;
		$db_row_id  = $element['id'] ?? false;

		if ( ! $element_id || ! $db_row_id ) {
			return;
		}

		// Get active job for this element
		$active_job = $this->get_active_job_for_element( $element_id );

		if ( $active_job && $remove_active_jobs ) {
			// Remove active job for this element if requested
			$this->remove_job( $active_job );
		} elseif ( $active_job ) {
			// exit if active job exists and removal is not requested
			return;
		}

		$filter_settings = json_decode( $element['settings'], true ) ?? false;
		$filter_source   = $filter_settings['filterSource'] ?? false;

		if ( ! $filter_settings || ! $filter_source ) {
			return;
		}

		// Validate job settings
		if ( ! self::validate_job_settings( $filter_source, $filter_settings ) ) {
			return;
		}

		// Get index args
		$args = self::get_index_args( $filter_source, $filter_settings );

		// Get total rows
		$query      = new \WP_Query( $args );
		$total_rows = count( $query->posts );

		// Release memory
		unset( $query );

		if ( $total_rows === 0 ) {
			return;
		}

		// Create a new job
		$job_data = [
			'filter_row_id' => $db_row_id, // Use the db row id
			'job_details'   => $element['settings'], // Store the settings without json_decode
			'total'         => $total_rows,
			'processed'     => 0,
		];

		$this->create_index_job( $job_data );
	}

	/**
	 * Create index job in index_job table
	 */
	private function create_index_job( $job_data ) {
		global $wpdb;

		$table_name = $this->query_filters::get_table_name( 'index_job' );

		$wpdb->insert( $table_name, $job_data );
	}


	/**
	 * Get next job from index_job table, left join with element table
	 */
	private function get_next_job() {
		global $wpdb;

		$job_table     = $this->query_filters::get_table_name( 'index_job' );
		$element_table = $this->query_filters::get_table_name( 'element' );

		$job = $wpdb->get_row( "SELECT * FROM {$job_table} LEFT JOIN {$element_table} ON {$job_table}.filter_row_id = {$element_table}.id ORDER BY {$job_table}.job_created_at ASC LIMIT 1", ARRAY_A );

		// Convert total, processed to integer
		if ( $job ) {
			$job['total']     = (int) $job['total'];
			$job['processed'] = (int) $job['processed'];
		}
		return $job;
	}

	/**
	 * Get active job for an element
	 */
	public function get_active_job_for_element( $filter_id ) {
		// Use sql to get the job rows, left join index_job.filter_row_id with element.id where element.filter_id = $filter_id
		global $wpdb;

		$job_table     = $this->query_filters::get_table_name( 'index_job' );
		$element_table = $this->query_filters::get_table_name( 'element' );

		$query = "SELECT * FROM {$job_table} LEFT JOIN {$element_table} ON {$job_table}.filter_row_id = {$element_table}.id WHERE {$element_table}.filter_id = %s";

		$job = $wpdb->get_row( $wpdb->prepare( $query, $filter_id ), ARRAY_A );

		return $job;
	}

	/**
	 * Update job
	 */
	private function update_job( $job, $data ) {
		global $wpdb;

		$id = $job['job_id'] ?? false;

		if ( ! $id ) {
			return;
		}

		$job_table = $this->query_filters::get_table_name( 'index_job' );

		$wpdb->update( $job_table, $data, [ 'job_id' => $id ] );
	}

	/**
	 * Complete job
	 */
	private function complete_job( $job ) {
		$id = $job['job_id'] ?? false;
		if ( ! $id ) {
			return;
		}

		// TODO: Maybe update a flag on the actual filter row to indicate that the indexing is completed, currently just remove the job
		$this->remove_job( $job );
	}

	/**
	 * Remove job
	 */
	private function remove_job( $job ) {
		global $wpdb;

		$id = $job['job_id'] ?? false;

		if ( ! $id ) {
			return;
		}

		$job_table = $this->query_filters::get_table_name( 'index_job' );

		$wpdb->delete( $job_table, [ 'job_id' => $id ] );
	}

	/**
	 * Get all jobs
	 */
	public function get_jobs() {
		global $wpdb;

		$job_table     = $this->query_filters::get_table_name( 'index_job' );
		$element_table = $this->query_filters::get_table_name( 'element' );

		$query = "SELECT * FROM {$job_table} LEFT JOIN {$element_table} ON {$job_table}.filter_row_id = {$element_table}.id";

		$jobs = $wpdb->get_results( $query, ARRAY_A );

		return $jobs;
	}

	/**
	 * Get the progress text for the indexing process
	 * - Use in the admin settings page
	 */
	public function get_overall_progress() {
		$text       = esc_html__( 'All indexing jobs completed.', 'bricks' );
		$all_jobs   = $this->get_jobs();
		$total_jobs = count( $all_jobs );

		if ( $total_jobs > 0 ) {
			// current job is the first job
			$current_job          = $all_jobs[0];
			$current_job_progress = round( ( $current_job['processed'] / $current_job['total'] ) * 100, 2 );

			// Show Total jobs, current job, progress
			$text  = esc_html__( 'Total jobs', 'bricks' ) . ': ' . $total_jobs . '<br>';
			$text .= esc_html__( 'Current job', 'bricks' ) . ': #' . $current_job['filter_id'] . '<br>';
			$text .= esc_html__( 'Progress', 'bricks' ) . ': ' . $current_job_progress . '%';
		}

		return $text;
	}


	/**
	 * Check if server resource limits are reached
	 * Default: 85% memory usage, and 20s time usage
	 * - Majority of servers have 30s time limit, save 10s for other processes
	 */
	public static function resource_limit_reached() {
		$default_time_limit      = 20;
		$memory_limit_percentage = 85;

		// Memory usage check
		$memory_limit = ini_get( 'memory_limit' );
		$memory_usage = memory_get_usage( true );

		// if not set or if set to '0' (unlimited)
		if ( ! $memory_limit || $memory_limit == -1 ) {
			$memory_limit = '512M';
		}

		$memory_limit = wp_convert_hr_to_bytes( $memory_limit );

		// Calculate current memory usage percentage
		$current_memory_percentage = ( $memory_usage / $memory_limit ) * 100;

		// Time usage check
		$time_limit = ini_get( 'max_execution_time' );
		$time_usage = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];

		// Use default time limit if not set or if set to '0' (unlimited)
		if ( ! $time_limit || $time_limit == 0 ) {
			$time_limit = $default_time_limit;
		}

		return ( $current_memory_percentage >= $memory_limit_percentage || $time_usage >= $time_limit );
	}

	/**
	 * Dispatch a background job (unblocking) to reindex query filters
	 */
	public static function trigger_background_job() {
		if ( ! Helpers::enabled_query_filters() ) {
			return;
		}

		$url = add_query_arg(
			[
				'action' => 'bricks_background_index_job',
				'nonce'  => wp_create_nonce( 'bricks-nonce-indexer' ) // Verify nonce in background_index_job
			],
			admin_url( 'admin-ajax.php' )
		);

		$args = [
			'sslverify' => false,
			'body'      => '',
			'timeout'   => 0.01,
			'blocking'  => false,
			'cookies'   => $_COOKIE,
		];

		Helpers::remote_post( $url, $args );
	}
}
