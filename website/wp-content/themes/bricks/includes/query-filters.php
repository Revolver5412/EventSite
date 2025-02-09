<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Query_Filters {
	const INDEX_TABLE_NAME     = 'bricks_filters_index';
	const ELEMENT_TABLE_NAME   = 'bricks_filters_element';
	const INDEX_JOB_TABLE_NAME = 'bricks_filters_index_job'; // @since 1.10
	const DB_CHECK_TRANSIENT   = 'bricks_filters_db_check'; // @since 1.10
	const OPTION_SUFFIX        = '_db_version'; // @since 1.10
	const ELEMENT_DB_VERSION   = '1.0'; // code version @since 1.10
	const INDEX_DB_VERSION     = '1.0'; // code version @since 1.10
	const INDEX_JOB_DB_VERSION = '1.0'; // code version @since 1.10

	private static $instance = null;
	private static $index_table_name;
	private static $element_table_name;
	private static $index_job_table_name; // @since 1.10

	public static $filter_object_ids       = [];
	public static $active_filters          = [];
	public static $page_filters            = [];
	public static $query_vars_before_merge = [];
	public static $is_saving_post          = false;

	public function __construct() {
		global $wpdb;

		self::$index_table_name     = $wpdb->prefix . self::INDEX_TABLE_NAME;
		self::$element_table_name   = $wpdb->prefix . self::ELEMENT_TABLE_NAME;
		self::$index_job_table_name = $wpdb->prefix . self::INDEX_JOB_TABLE_NAME; // @since 1.10

		if ( Helpers::enabled_query_filters() ) {
			// Check required tables (@since 1.10)
			add_action( 'admin_init', [ $this, 'tables_check' ] );
			add_action( 'wp', [ $this, 'maybe_set_page_filters' ], 100 );

			/**
			 * Capture filter elements and index if needed
			 * Use update_post_metadata to capture filter elements when duplicate content.
			 * Priority 11 after the hook check in ajax.php
			 *
			 * @since 1.9.8
			 */
			add_action( 'update_post_metadata', [ $this, 'maybe_update_element' ], 11, 5 );

			// Hooks to listen so we can add new index record. Use largest priority
			add_action( 'save_post', [ $this, 'save_post' ], PHP_INT_MAX - 10, 2 );
			add_action( 'delete_post', [ $this, 'delete_post' ] );
			add_filter( 'wp_insert_post_parent', [ $this, 'wp_insert_post_parent' ], 10, 4 );
			add_action( 'set_object_terms', [ $this, 'set_object_terms' ], PHP_INT_MAX - 10, 6 );

			// Term
			add_action( 'edited_term', [ $this, 'edited_term' ], PHP_INT_MAX - 10, 3 );
			add_action( 'delete_term', [ $this, 'delete_term' ], 10, 4 );

			// Element conditions all true for filter elements in filter API endpoints (@since 1.9.8)
			add_filter( 'bricks/element/render', [ $this, 'filter_element_render' ], 10, 2 );
		}
	}

	/**
	 * Singleton - Get the instance of this class
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new Query_Filters();
		}

		return self::$instance;
	}

	/**
	 * Get the database key for the given table name
	 * - To be used in options table
	 * - Example: bricks_filters_index_db_version
	 *
	 * @since 1.10
	 */
	private static function get_option_key( $table_type = 'index' ) {
		if ( $table_type === 'element' ) {
			$option_key = self::ELEMENT_TABLE_NAME;
		} elseif ( $table_type === 'index_job' ) {
			$option_key = self::INDEX_JOB_TABLE_NAME;
		} else {
			$option_key = self::INDEX_TABLE_NAME;
		}

		return $option_key . self::OPTION_SUFFIX;
	}

	public static function get_table_name( $table_name = 'index' ) {
		if ( $table_name === 'element' ) {
			return self::$element_table_name;
		} elseif ( $table_name === 'index_job' ) {
			return self::$index_job_table_name;
		}

		return self::$index_table_name;
	}

	public static function check_managed_db_access() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Create custom database table for storing filter index
	 */
	public function maybe_create_tables() {
		if ( ! self::check_managed_db_access() ) {
			return;
		}

		$this->create_index_table();
		$this->create_element_table();
		$this->create_index_job_table();
	}

	private function create_index_job_table() {
		global $wpdb;

		$index_job_table_name = self::get_table_name( 'index_job' );

		// Return: Table already exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index_job_table_name ) ) === $index_job_table_name ) {
			return;
		}

		// Create table
		$charset_collate = $wpdb->get_charset_collate();

		/**
		 * Table columns:
		 * filter_row_id: Reference to the filter element row ID
		 * job_details: The details of the job
		 * total: The total rows to be processed
		 * processed: The total rows processed
		 * job_created_at: The time the job was created
		 * job_updated_at: The time the job was updated
		 *
		 * Indexes:
		 * filter_row_id_idx (filter_row_id)
		 */
		$sql = "CREATE TABLE {$index_job_table_name} (
			job_id BIGINT(20) NOT NULL AUTO_INCREMENT,
			filter_row_id BIGINT(20) UNSIGNED,
			job_details LONGTEXT,
			total BIGINT(20) UNSIGNED default '0',
			processed BIGINT(20) UNSIGNED default '0',
			job_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			job_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (job_id),
			KEY filter_row_id_idx (filter_row_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
	}

	private function create_index_table() {
		global $wpdb;

		$index_table_name = self::get_table_name();

		// Return: Table already exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index_table_name ) ) === $index_table_name ) {
			return;
		}

		// Create table
		$charset_collate = $wpdb->get_charset_collate();

		/**
		 * Table columns:
		 * filter_id: The unique 6-character filter element ID
		 * object_id: The ID of the post/page
		 * object_type: The type of object (post, page, etc.)
		 * filter_value: The value of the filter
		 * filter_value_display: The value of the filter (displayed)
		 * filter_value_id: The ID of the filter value (if applicable)
		 * filter_value_parent: The parent ID of the filter value (if applicable)
		 *
		 * Indexes:
		 * filter_id_idx (filter_id)
		 * object_id_idx (object_id)
		 * filter_id_object_id_idx (filter_id, object_id)
		 */
		$sql = "CREATE TABLE {$index_table_name} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			filter_id CHAR(6) NOT NULL,
			object_id BIGINT(20) UNSIGNED,
			object_type VARCHAR(50),
			filter_value VARCHAR(255),
			filter_value_display VARCHAR(255),
			filter_value_id BIGINT(20) UNSIGNED default '0',
			filter_value_parent BIGINT(20) UNSIGNED default '0',
			PRIMARY KEY  (id),
			KEY filter_id_idx (filter_id),
			KEY object_id_idx (object_id),
			KEY filter_id_object_id_idx (filter_id, object_id)
    ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
	}

	private function create_element_table() {
		global $wpdb;

		$element_table_name = self::get_table_name( 'element' );

		// Return: Table already exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $element_table_name ) ) === $element_table_name ) {
			return;
		}

		// Create table
		$charset_collate = $wpdb->get_charset_collate();

		/**
		 * This table is used to store all filter elements created across the site
		 *
		 * When a post update or save, we will loop through all filter elements and update the index table
		 * Table columns:
		 * filter_id: The unique 6-character filter element ID
		 * filter_action: The action of the filter element (filter, sort)
		 * status: The status of the filter element (0, 1)
		 * indexable: Whether this filter element is indexable (0, 1)
		 * settings: The settings of the filter element
		 * post_id: The ID of this filter element located in
		 *
		 * Indexes:
		 * filter_id_idx (filter_id)
		 * filter_action_idx (filter_action)
		 * status_idx (status)
		 * indexable_idx (indexable)
		 * post_id_idx (post_id)
		 */

		$sql = "CREATE TABLE {$element_table_name} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			filter_id CHAR(6) NOT NULL,
			filter_action VARCHAR(50),
			status INT UNSIGNED default '0',
			indexable INT UNSIGNED default '0',
			settings LONGTEXT,
			post_id BIGINT(20) UNSIGNED,
			PRIMARY KEY  (id),
			KEY filter_id_idx (filter_id),
			KEY filter_action_idx (filter_action),
			KEY status_idx (status),
			KEY indexable_idx (indexable),
			KEY post_id_idx (post_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
	}

	/**
	 * Handle table structure update
	 *
	 * @since 1.10
	 */
	private function maybe_update_table_structure() {
		if ( ! self::check_managed_db_access() ) {
			return;
		}

		$tables = [
			'element'   => [
				'code_version' => self::ELEMENT_DB_VERSION,
				'update_fn'    => [
					'1.0' => 'update_filter_element_db_v_1_0',
					'1.1' => 'update_filter_element_db_v_1_1', // Not exists, future reference
				],
			],
			'index'     => [
				'code_version' => self::INDEX_DB_VERSION,
				'update_fn'    => [
					'1.0' => 'update_filter_index_db_v_1_0',
				],
			],
			'index_job' => [
				'code_version' => self::INDEX_JOB_DB_VERSION,
				'update_fn'    => [
					'1.0' => 'update_filter_index_job_db_v_1_0',
				],
			]
		];

		foreach ( $tables as $table_type => $table_info ) {
			// Get element db version, default is 0.1
			$table_db_version = get_option( self::get_option_key( $table_type ), '0.1' );

			// Exit if db version is higher or equal to code version
			if ( version_compare( $table_db_version, $table_info['code_version'], '>=' ) ) {
				continue;
			}

			/**
			 * Loop through all update functions
			 * Ensure any website instance can update every version althought it's a small performance hit
			 */
			foreach ( $table_info['update_fn'] as $version => $function_name ) {
				if ( version_compare( $table_db_version, $version, '<' ) && method_exists( $this, $function_name ) ) {
					$this->$function_name();
				}
			}

		}

	}

	/**
	 * Check if all database tables are updated
	 * Used in admin settings page
	 *
	 * @since 1.10
	 */
	public static function all_db_updated() {
		$element_db_version = get_option( self::get_option_key( 'element' ), '0.1' );
		if ( version_compare( $element_db_version, self::ELEMENT_DB_VERSION, '<' ) ) {
			return false;
		}

		$index_db_version = get_option( self::get_option_key( 'index' ), '0.1' );
		if ( version_compare( $index_db_version, self::INDEX_DB_VERSION, '<' ) ) {
			return false;
		}

		$index_job_db_version = get_option( self::get_option_key( 'index_job' ), '0.1' );
		if ( version_compare( $index_job_db_version, self::INDEX_JOB_DB_VERSION, '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * v1.0 element db version update
	 * - Update post_id to BIGINT(20) UNSIGNED
	 *
	 * @since 1.10
	 */
	private function update_filter_element_db_v_1_0() {
		$fn_version         = '1.0';
		$db_key             = self::get_option_key( 'element' );
		$current_db_version = get_option( $db_key, '0.1' );

		// STEP: If current db version is higher or equal to 1.0, return
		if ( version_compare( $current_db_version, $fn_version, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table_name = self::get_table_name( 'element' );

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		// STEP: Alter element table
		// post_id to BIGINT(20) UNSIGNED, remain index as is
		$sql = "ALTER TABLE {$table_name} MODIFY post_id BIGINT(20) UNSIGNED, DROP INDEX post_id_idx, ADD INDEX post_id_idx (post_id)";

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Update element db version, no auto-load
		update_option( $db_key, $fn_version, false );
	}

	/**
	 * v1.0 index db version update
	 * - Update object_id, filter_value_id, filter_value_parent to BIGINT(20) UNSIGNED
	 *
	 * @since 1.10
	 */
	private function update_filter_index_db_v_1_0() {
		$fn_version         = '1.0';
		$db_key             = self::get_option_key( 'index' );
		$current_db_version = get_option( $db_key, '0.1' );

		// STEP: If current db version is higher or equal to 1.0, return
		if ( version_compare( $current_db_version, $fn_version, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table_name = self::get_table_name();

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		// STEP: Alter index table
		// object_id, filter_value_id, filter_value_parent to BIGINT(20) UNSIGNED, remain index as is
		$sql = "ALTER TABLE {$table_name} MODIFY object_id BIGINT(20) UNSIGNED, MODIFY filter_value_id BIGINT(20) UNSIGNED, MODIFY filter_value_parent BIGINT(20) UNSIGNED, DROP INDEX object_id_idx, DROP INDEX filter_id_object_id_idx, ADD INDEX object_id_idx (object_id), ADD INDEX filter_id_object_id_idx (filter_id, object_id)";

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Update index db version, no auto-load
		update_option( $db_key, $fn_version, false );
	}

	/**
	 * v1.0 index job db version update
	 * - Update total, processed to BIGINT(20) UNSIGNED
	 *
	 * @since 1.10
	 */
	private function update_filter_index_job_db_v_1_0() {
		$fn_version         = '1.0';
		$db_key             = self::get_option_key( 'index_job' );
		$current_db_version = get_option( $db_key, '0.1' );

		// STEP: If current db version is higher or equal to 1.0, return
		if ( version_compare( $current_db_version, $fn_version, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table_name = self::get_table_name( 'index_job' );

		// Check if table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		// STEP: Alter index job table
		// total, processed to BIGINT(20) UNSIGNED, remain index as is
		$sql = "ALTER TABLE {$table_name} MODIFY total BIGINT(20) UNSIGNED, MODIFY processed BIGINT(20) UNSIGNED";

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Update index job db version, no auto-load
		update_option( $db_key, $fn_version, false );
	}

	/**
	 * Check if the required tables exist
	 *
	 * @since 1.10
	 */
	public function tables_check() {
		// Check: DB last checked time
		$db_check = get_transient( self::DB_CHECK_TRANSIENT );
		$ttl      = 28800; // = 8 hours

		// Check: DB tables need to be checked
		if ( ! $db_check || ( time() - $db_check ) > $ttl ) {
			$this->maybe_create_tables();
			$this->maybe_update_table_structure(); // @since 1.10
			set_transient( self::DB_CHECK_TRANSIENT, time(), $ttl );
		}
	}

	/**
	 * Return array of element names that have filter settings.
	 *
	 * Pagination is one of them but it's filter setting handled in /includes/elements/pagination.php set_ajax_attributes()
	 */
	public static function filter_controls_elements() {
		return [
			'filter-checkbox',
			'filter-datepicker',
			'filter-radio',
			'filter-range',
			'filter-search',
			'filter-select',
			'filter-submit',
			'filter-range',
		];
	}

	/**
	 * Dynamic update elements names
	 * - These elements will be updated dynamically when the filter AJAX is called
	 */
	public static function dynamic_update_elements() {
		return [
			'filter-checkbox',
			'filter-datepicker',
			'filter-radio',
			'filter-range',
			'filter-search',
			'filter-select',
			'filter-range',
			'pagination', // @since 1.9.8
		];
	}

	/**
	 * Indexable elements names
	 * - These elements will be indexed in the index table
	 */
	public static function indexable_elements() {
		return [
			'filter-checkbox',
			'filter-datepicker',
			'filter-radio',
			'filter-select',
			'filter-range',
		];
	}

	/**
	 * Force render filter elements in filter API endpoint.
	 *
	 * Otherwise, filter elements will not be re-rendered in filter API endpoint as element condition fails.
	 *
	 * @since 1.9.8
	 */
	public function filter_element_render( $render, $element_instance ) {
		$element_name = is_object( $element_instance ) ? $element_instance->name : false;

		if ( ! $element_name ) {
			$element_name = $element_instance['name'] ?? false;
		}

		// Check: Is this a dynamic update element
		if ( ! in_array( $element_name, self::dynamic_update_elements(), true ) ) {
			return $render;
		}

		// Return true for dynamic update elements (if this is filter API endpoint)
		if ( Api::is_current_endpoint( 'query_result' ) ) {
			return true;
		}

		return $render;
	}

	/**
	 * Set page filters manually on wp hook:
	 * Example: In archive page, taxonomy page, etc.
	 */
	public function maybe_set_page_filters() {
		// Check if this is taxonomy page
		if ( is_tax() || is_category() || is_tag() || is_post_type_archive() ) {
			// What is current taxonomy?
			$queried_object = get_queried_object();

			$taxonomy = $queried_object->taxonomy ?? false;

			if ( ! $taxonomy ) {
				return;
			}

			// Set current page filters so each filter element can disabled as needed
			self::$page_filters[ $taxonomy ] = $queried_object->slug;
		}
	}

	/**
	 * Hook into update_post_metadata, if filter element found, update the index table
	 */
	public function maybe_update_element( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		// Exclude revisions
		if ( wp_is_post_revision( $object_id ) ) {
			return $check;
		}

		// Only listen to header, content, footer
		if ( ! in_array( $meta_key, [ BRICKS_DB_PAGE_HEADER, BRICKS_DB_PAGE_CONTENT, BRICKS_DB_PAGE_FOOTER ], true ) ) {
			return $check;
		}

		$filter_elements = [];
		// Get all filter elements from meta_value
		foreach ( $meta_value as $element ) {
			$element_id = $element['id'] ?? false;

			if ( ! $element_id ) {
				continue;
			}

			$element_name = $element['name'] ?? false;

			if ( ! in_array( $element_name, self::filter_controls_elements(), true ) ) {
				continue;
			}

			$filter_elements[ $element_id ] = $element;
		}

		if ( ! empty( $filter_elements ) ) {
			// Update element table
			$updated_data = $this->update_element_table( $filter_elements, $object_id );

			// Now we need to update the index table by using the updated_data
			$this->update_index_table( $updated_data );
		}

		return $check;
	}

	/**
	 *  Decide whether create, update or delete elements in the element table
	 *  Return: array of new_elements, updated_elements, deleted_elements
	 *  Index table will use the return data to decide what to do
	 */
	private function update_element_table( $elements, $post_id ) {
		// Get all elements from element table where post_id = $post_id
		$all_db_elements = $this->get_elements_from_element_table(
			[
				'post_id' => $post_id,
			]
		);

		// Just get the filter_id
		$all_db_elements_ids = array_column( $all_db_elements, 'filter_id' );

		$update_data = [
			'new_elements'     => [],
			'updated_elements' => [],
			'deleted_elements' => [],
		];

		// Loop through all elements from element table
		foreach ( $all_db_elements_ids as $key => $db_element_id ) {
			// If this element is not in the new elements, delete it
			if ( ! isset( $elements[ $db_element_id ] ) ) {
				$this->delete_element( [ 'filter_id' => $db_element_id ] );
				$update_data['deleted_elements'][] = $all_db_elements[ $key ];
			}
		}

		// Loop through all elements, create or update them into element table
		foreach ( $elements as $element ) {
			$element_id = $element['id'] ?? false;

			if ( ! $element_id ) {
				continue;
			}

			$filter_settings = $element['settings'] ?? [];
			$filter_action   = $filter_settings['filterAction'] ?? 'filter';
			$indexable       = in_array( $element['name'], self::indexable_elements(), true ) && 'filter' === $filter_action ? 1 : 0;
			$filterQueryId   = $filter_settings['filterQueryId'] ?? false;

			$element_data = [
				'filter_id'     => $element_id,
				'filter_action' => $filter_action,
				'status'        => ! empty( $filterQueryId ) ? 1 : 0,
				'indexable'     => $indexable,
				'settings'      => wp_json_encode( $filter_settings ),
				'post_id'       => $post_id,
			];

			// If this element is not in the db elements, create it
			if ( ! in_array( $element_id, $all_db_elements_ids, true ) ) {
				$inserted_id = $this->create_element( $element_data );
				if ( $inserted_id > 0 ) {
					// Add id to $element_data so we can use it in update_index_table
					$element_data['id']            = $inserted_id;
					$update_data['new_elements'][] = $element_data;
				}
			} else {
				// If this element is in the db elements, update it
				$updated_id = $this->update_element( $element_data );
				// Only add to updated_elements if updated_id is not false
				if ( $updated_id > 0 ) {
					// Add id to $element_data so we can use it in update_index_table
					$element_data['id']                = $updated_id;
					$update_data['updated_elements'][] = $element_data;
				}

			}
		}

		return $update_data;
	}

	/**
	 * Remove index DB table and recreate it.
	 * Retrieve all indexable elements from element table.
	 * Index based on the element settings.
	 */
	public function reindex() {
		if ( ! self::check_managed_db_access() ) {
			return [ 'error' => 'Access denied (current user can\'t manage_options)' ];
		}

		global $wpdb;

		$table_name = self::get_table_name();

		// Always drop index table and recreate it
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Ensure all tables created - @since 1.10
		$this->maybe_create_tables();

		// Ensure all tables updated - @since 1.10
		$this->maybe_update_table_structure();

		// Exit if index table does not exist
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return [ 'error' => "Table {$table_name} does not exist" ];
		}

		// Get all indexable elements from element table
		$indexable_elements = $this->get_elements_from_element_table(
			[
				'indexable' => 1,
				'status'    => 1,
			]
		);

		$element_data = [
			'new_elements'     => [],
			'deleted_elements' => [],
			'updated_elements' => $indexable_elements,
		];

		$this->update_index_table( $element_data );

		return true;
	}

	/**
	 * Update index table based on the updated_data
	 * updated_data holds new_elements, updated_elements, deleted_elements
	 * 1. Remove all rows related to the deleted_elements
	 * 2. Generate index for all new_elements and updated_elements
	 */
	private function update_index_table( $updated_data ) {
		// STEP: Handle deleted elements
		foreach ( $updated_data['deleted_elements'] as $deleted_element ) {
			$id = $deleted_element['filter_id'] ?? false;
			if ( ! $id ) {
				continue;
			}

			// Remove rows related to this filter_id
			self::remove_index_rows( [ 'filter_id' => $id ] );
		}

		// STEP: Handle updated elements
		foreach ( $updated_data['updated_elements'] as $updated_element ) {
			$id = $updated_element['filter_id'] ?? false;
			if ( ! $id ) {
				continue;
			}

			// Remove rows related to this filter_id
			self::remove_index_rows( [ 'filter_id' => $id ] );
		}

		// STEP: Handle new elements & updated elements (we can retrieve from database again but we already have the data)
		$elements = array_merge( $updated_data['new_elements'], $updated_data['updated_elements'] );

		// Only get elements that are indexable, status is 1, and filter_action is filter
		$indexable_elements = array_filter(
			$elements,
			function ( $element ) {
				$indexable     = $element['indexable'] ?? false;
				$status        = $element['status'] ?? false;
				$filter_action = $element['filter_action'] ?? false;

				if ( ! $indexable || ! $status || $filter_action !== 'filter' ) {
					return false;
				}

				return true;
			}
		);

		$indexer = Query_Filters_Indexer::get_instance();

		// STEP: Send each element to the job queue (@since 1.10)
		foreach ( $indexable_elements as $indexable_element ) {
			// filter_settings is json string
			$filter_settings = json_decode( $indexable_element['settings'], true );
			$filter_db_id    = $indexable_element['id'] ?? false;
			$filter_id       = $indexable_element['filter_id'] ?? false;
			$filter_source   = $filter_settings['filterSource'] ?? false;

			// Ensure all required data is available
			if ( ! $filter_source || ! $filter_db_id || ! $filter_id ) {
				continue;
			}

			$indexer->add_job( $indexable_element );
		}

		// STEP: Trigger the job once
		$indexer::trigger_background_job();
	}


	/**
	 * Get all elements from element table where post_id = $post_id
	 */
	private function get_elements_from_element_table( $args = [] ) {
		global $wpdb;

		$table_name = self::get_table_name( 'element' );

		// Initialize an empty array to store placeholders and values
		$placeholders = [];
		$values       = [];
		$where_clause = '';

		// Loop through all args and build where clause
		foreach ( $args as $key => $value ) {
			$placeholders[] = $key . ' = %s';
			$values[]       = $value;
		}

		// If we have placeholders, build where clause
		if ( ! empty( $placeholders ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $placeholders );
		}

		$query = "SELECT * FROM {$table_name} {$where_clause}";

		$all_elements = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A );

		return $all_elements ?? [];
	}

	/**
	 * Delete element from element table
	 */
	private function delete_element( $args = [] ) {
		if ( empty( $args ) ) {
			return;
		}

		global $wpdb;

		$table_name = self::get_table_name( 'element' );

		// Check if array_keys is filter_id or post_id
		$column = array_keys( $args )[0];

		if ( ! in_array( $column, [ 'filter_id', 'post_id' ], true ) ) {
			return;
		}

		$wpdb->delete(
			$table_name,
			$args
		);
	}

	/**
	 * Create element in element table
	 */
	private function create_element( $element_data ) {
		global $wpdb;

		$table_name = self::get_table_name( 'element' );

		$element_id = $element_data['filter_id'] ?? false;

		if ( ! $element_id ) {
			return false;
		}

		// Insert element into element table
		$wpdb->insert( $table_name, $element_data );

		// Return inserted ID
		return $wpdb->insert_id;
	}

	/**
	 * Update element in element table
	 *
	 * Only update if the new data is different from the current data
	 *
	 * @return int The ID of the updated element or false if no update needed
	 */
	private function update_element( $element_data ) {
		global $wpdb;

		$table_name = self::get_table_name( 'element' );

		$element_id = $element_data['filter_id'] ?? false;

		if ( ! $element_id ) {
			return false;
		}

		// Get the element data from the element table
		$db_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE filter_id = %s",
				$element_id
			),
			ARRAY_A
		);

		// If no element found, return
		if ( ! $db_data ) {
			return false;
		}

		$needs_update = false;

		// Check if the new data is different from the current data
		foreach ( $element_data as $key => $value ) {
			if ( (string) $db_data[ $key ] !== (string) $value ) {
				$needs_update = true;
				break;
			}
		}

		// If no update needed, return
		if ( ! $needs_update ) {
			return false;
		}

		// Update element in element table
		$wpdb->update(
			$table_name,
			$element_data,
			[
				'filter_id' => $element_id,
			]
		);

		// Return updated data
		return $db_data['id'];
	}

	/**
	 * Generate index records for a given taxonomy
	 */
	public static function generate_taxonomy_index_rows( $all_posts_ids, $taxonomy ) {

		$rows = [];
		// Loop through all posts
		foreach ( $all_posts_ids as $post_id ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			// If no terms, skip
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			// Loop through all terms
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
		}

		return $rows;

	}

	/**
	 * Remove rows from database
	 */
	public static function remove_index_rows( $args = [] ) {
		global $wpdb;

		$table_name = self::get_table_name();

		if ( empty( $args ) ) {
			return;
		}

		// Remove rows
		$wpdb->delete( $table_name, $args );
	}

	/**
	 * Insert rows into database
	 */
	public static function insert_index_rows( $rows ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$insert_values = [];

		foreach ( $rows as $row ) {
			$insert_values[] = $wpdb->prepare(
				'( %s, %d, %s, %s, %s, %d, %d )',
				$row['filter_id'],
				$row['object_id'],
				$row['object_type'],
				$row['filter_value'],
				$row['filter_value_display'],
				$row['filter_value_id'],
				$row['filter_value_parent']
			);
		}

		if ( ! empty( $insert_values ) ) {
			$insert_query = "INSERT INTO {$table_name}
			( filter_id, object_id, object_type, filter_value, filter_value_display, filter_value_id, filter_value_parent )
			VALUES " . implode( ', ', $insert_values );

			$wpdb->query( $insert_query );
		}

	}

	/**
	 * Generate index records for a given custom field
	 */
	public static function generate_custom_field_index_rows( $post_ids, $meta_key ) {
		$rows = [];

		// Loop through all posts
		foreach ( $post_ids as $post_id ) {
			$meta_value = get_post_meta( $post_id, $meta_key, true );

			// Populate rows
			$rows[] = [
				'filter_id'            => '',
				'object_id'            => $post_id,
				'object_type'          => 'post',
				'filter_value'         => $meta_value,
				'filter_value_display' => $meta_value,
				'filter_value_id'      => 0,
				'filter_value_parent'  => 0,
			];
		}

		return $rows;

	}

	/**
	 * Generate index records for a given post field.
	 *
	 * @param array  $posts Array of post objects
	 * @param string $post_field The post field to be used
	 */
	public static function generate_post_field_index_rows( $posts, $post_field ) {

		$rows = [];

		// Change field name if needed so we can get it from post object
		$post_field = $post_field === 'post_id' ? 'ID' : $post_field;

		// Loop through all posts and get the post fields value
		foreach ( $posts as $post ) {
			if ( ! is_a( $post, 'WP_Post' ) ) {
				continue;
			}

			// Populate rows
			$value         = $post->$post_field ?? false;
			$display_value = $value ?? 'None';

			// If post field is post_author, get the author name
			if ( $post_field === 'post_author' ) {
				$author        = get_user_by( 'id', $value );
				$display_value = $author->display_name ?? 'None';
			}

			$rows[] = [
				'filter_id'            => '',
				'object_id'            => $post->ID,
				'object_type'          => 'post',
				'filter_value'         => $value,
				'filter_value_display' => $display_value,
				'filter_value_id'      => 0,
				'filter_value_parent'  => 0,
			];
		}

		return $rows;

	}

	/**
	 * Updated filters to be used in frontend after each filter ajax request
	 */
	public static function get_updated_filters( $filters = [], $post_id = 0 ) {
		$updated_filters = [];

		// Loop through all filter_ids and gather elements that need to be updated
		$valid_elements = [];
		$active_filters = [];

		foreach ( $filters as $filter_id => $current_value ) {
			$element_data   = Helpers::get_element_data( $post_id, $filter_id );
			$filter_element = $element_data['element'] ?? false;

			// Check if $filter_element exists
			if ( ! $filter_element || empty( $filter_element ) ) {
				continue;
			}

			if ( ! in_array( $filter_element['name'], self::dynamic_update_elements(), true ) ) {
				continue;
			}

			$filter_settings = $filter_element['settings'] ?? [];
			$filter_action   = $filter_settings['filterAction'] ?? 'filter';

			// Skip: filter_action is not set to filter
			if ( $filter_action !== 'filter' ) {
				continue;
			}

			$has_value = false;

			// $current_value can be an array, check value is not empty too
			if ( is_array( $current_value ) ) {
				// Ensure all values are not empty
				$values_in_array = array_filter( $current_value, 'strlen' );
				if ( ! empty( $values_in_array ) ) {
					$has_value = true;
				}
			} elseif ( ! empty( $current_value ) ) {
				$has_value = true;
			}

			// Has value, set it as active filter and update filter element settings
			if ( $has_value ) {
				$filter_element['settings']['filterValue'] = $current_value;
				$active_filters[ $filter_id ]              = $current_value;
			}

			// Valid elements will regenerate new HTML
			$valid_elements[ $filter_id ] = $filter_element;
		}

		// Set active filters (ensure unique filters)
		self::$active_filters = empty( self::$active_filters ) ? $active_filters : array_unique( array_merge( self::$active_filters, $active_filters ) );

		// Loop through all valid elements and generate HTML
		foreach ( $valid_elements as $filter_id => $element ) {
			$updated_filters[ $filter_id ] = Frontend::render_element( $element );
		}

		return $updated_filters;
	}

	/**
	 * Get filtered data from index table
	 */
	public static function get_filtered_data_from_index( $filter_id = '', $object_ids = [] ) {
		if ( empty( $filter_id ) ) {
			return [];
		}

		global $wpdb;

		$table_name = self::get_table_name();

		$where_clause = '';
		$params       = [ $filter_id ];

		// If object_ids is set, add to where clause
		if ( ! empty( $object_ids ) ) {
			$placeholders = array_fill( 0, count( $object_ids ), '%d' );
			$placeholders = implode( ',', $placeholders );
			$where_clause = "AND object_id IN ({$placeholders})";
			$params       = array_merge( $params, $object_ids );
		}

		$sql = "SELECT filter_value, filter_value_display, filter_value_id, filter_value_parent, COUNT(DISTINCT object_id) AS count
		FROM {$table_name}
		WHERE filter_id = %s {$where_clause}
		GROUP BY filter_value, filter_value_display, filter_value_id, filter_value_parent";

		// Get all filter values for this filter_id
		$filter_values = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				$params
			),
			ARRAY_A
		);

		return $filter_values ?? [];
	}

	/**
	 * Get all possible object ids from a query
	 * To be used in get_filtered_data()
	 * Each query_id will only be queried once
	 *
	 * @param string $query_id
	 * @return array $all_posts_ids
	 */
	public static function get_filter_object_ids( $query_id = '', $source = 'history' ) {
		if ( empty( $query_id ) ) {
			return [];
		}

		$cache_key = $query_id . '_' . $source;
		// Check if query_id is inside self::$filter_object_ids, if yes, return the object_ids
		if ( isset( self::$filter_object_ids[ $cache_key ] ) ) {
			return self::$filter_object_ids[ $cache_key ];
		}

		$query_data = Query::get_query_by_element_id( $query_id );

		// Return empty array if query_data is empty
		if ( ! $query_data ) {
			return [];
		}

		$query_vars = $query_data->query_vars ?? [];

		if ( $source === 'original' && isset( self::$query_vars_before_merge[ $query_id ] ) ) {
			$query_vars = self::$query_vars_before_merge[ $query_id ];
		}

		$query_type = $query_data->object_type ?? 'post';

		// Beta only support post query type
		if ( $query_type !== 'post' ) {
			return [];
		}

		// Use the query_vars and get all possible post ids
		$all_posts_args = array_merge(
			$query_vars,
			[
				'paged'                  => 1,
				'posts_per_page'         => -1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'cache_results'          => false,
				'no_found_rows'          => true,
				'nopaging'               => true,
				'fields'                 => 'ids',
			]
		);

		$all_posts = new \WP_Query( $all_posts_args );

		$all_posts_ids = $all_posts->posts;

		// Store the object_ids in self::$filter_object_ids
		self::$filter_object_ids[ $cache_key ] = $all_posts_ids;

		return $all_posts_ids;
	}

	/**
	 * Generate index when a post is saved
	 */
	public function save_post( $post_id, $post ) {

		// Revision
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// auto-draft
		if ( $post->post_status === 'auto-draft' ) {
			return;
		}

		$this->index_post( $post_id );
		self::$is_saving_post = false;
	}

	/**
	 * Remove index when a post is deleted
	 */
	public function delete_post( $post_id ) {
		// Remove rows related to this post_id
		self::remove_index_rows(
			[
				'object_id'   => $post_id,
				'object_type' => 'post',
			]
		);

		/**
		 * Maybe this post contains filter elements
		 *
		 * Must remove filter elements from element table, and related rows from index table.
		 *
		 * @since 1.9.8
		 */
		// STEP: Get all filter elements from this post_id
		$all_db_elements = $this->get_elements_from_element_table( [ 'post_id' => $post_id ] );

		// Just get the filter_id
		$all_db_elements_ids = array_column( $all_db_elements, 'filter_id' );

		// STEP: Remove rows related to these filter_ids
		foreach ( $all_db_elements_ids as $filter_id ) {
			self::remove_index_rows( [ 'filter_id' => $filter_id ] );
		}

		// Remove elements related to this post_id
		$this->delete_element( [ 'post_id' => $post_id ] );
	}

	/**
	 * Set is_saving_post to true when a post is assigned to a parent to avoid reindexing
	 * Triggered when using wp_insert_post()
	 */
	public function wp_insert_post_parent( $post_parent, $post_id, $new_postarr, $postarr ) {
		// Set is_saving_post to true
		self::$is_saving_post = true;

		return $post_parent;
	}

	/**
	 * Generate index when a post is assigened to a term
	 * Triggered when using wp_set_post_terms() or wp_set_object_terms()
	 */
	public function set_object_terms( $object_id ) {
		if ( self::$is_saving_post ) {
			return;
		}

		$this->index_post( $object_id );
	}

	/**
	 * Core function to index a post based on all active indexable filter elements
	 *
	 * @param int $post_id
	 */
	public function index_post( $post_id ) {
		if ( empty( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		// Get all indexable and active filter elements from element table
		$indexable_elements = $this->get_elements_from_element_table(
			[
				'indexable' => 1,
				'status'    => 1
			]
		);

		if ( empty( $indexable_elements ) ) {
			return;
		}

		// Loop through all indexable elements and group them up by filter_source
		$grouped_elements = [];

		foreach ( $indexable_elements as $element ) {
			// filter_settings is json string
			$filter_settings = json_decode( $element['settings'], true );
			$filter_source   = $filter_settings['filterSource'] ?? false;

			if ( ! $filter_source ) {
				continue;
			}

			// Update filter_settings properly
			$element['settings'] = $filter_settings;

			if ( $filter_source === 'taxonomy' ) {
				$filter_taxonomy = $filter_settings['filterTaxonomy'] ?? false;
				if ( ! $filter_taxonomy ) {
					continue;
				}
				$key                        = $filter_source . '|' . $filter_taxonomy;
				$grouped_elements[ $key ][] = $element;
			} else {
				$grouped_elements[ $filter_source ][] = $element;
			}

		}

		// Loop through all grouped elements and generate index
		foreach ( $grouped_elements as $source => $elements ) {
			$rows_to_insert = [];

			// Build $rows
			switch ( $source ) {
				case 'wpField':
					$post_fields = [];
					foreach ( $elements as $element ) {
						// check what is the selected field
						$filter_settings = $element['settings'];
						$field_type      = $filter_settings['sourceFieldType'] ?? 'post';

						if ( ! $field_type ) {
							continue;
						}

						$selected_field = false;
						switch ( $field_type ) {
							case 'post':
								$selected_field = $filter_settings['wpPostField'] ?? false;

								if ( ! $selected_field ) {
									continue 2;
								}

								if ( isset( $post_fields[ $selected_field ] ) ) {
									$post_fields[ $selected_field ][] = $element['filter_id'];
								} else {
									$post_fields[ $selected_field ] = [ $element['filter_id'] ];
								}

								break;

							case 'user':
								$selected_field = $filter_settings['wpUserField'] ?? false;
								break;

							case 'term':
								$selected_field = $filter_settings['wpTermField'] ?? false;
								break;
						}
					}

					if ( ! empty( $post_fields ) ) {
						// Generate rows for each post_field
						foreach ( $post_fields as $post_field => $filter_ids ) {

							$rows_for_this_post_field = self::generate_post_field_index_rows( [ $post ], $post_field );

							// Build $rows_to_insert
							if ( ! empty( $rows_for_this_post_field ) && ! empty( $filter_ids ) ) {
								// Add filter_id to each row, row is the standard template, do not overwrite it.
								foreach ( $filter_ids as $filter_id ) {
									$rows_to_insert = array_merge(
										$rows_to_insert,
										array_map(
											function( $row ) use ( $filter_id ) {
												$row['filter_id'] = $filter_id;

												return $row;
											},
											$rows_for_this_post_field
										)
									);
								}
							}

							// Remove rows related to this filter_id and post_id
							foreach ( $filter_ids as $filter_id ) {
								self::remove_index_rows(
									[
										'filter_id' => $filter_id,
										'object_id' => $post_id,
									]
								);
							}
						}

					}

					break;

				case 'customField':
					$meta_keys = [];

					// Gather all meta keys from each element settings
					foreach ( $elements as $element ) {
						// filter_settings is json string
						$filter_settings = $element['settings'];
						$meta_key        = $filter_settings['customFieldKey'] ?? false;

						if ( ! $meta_key ) {
							continue;
						}

						// Add filter_id to existing meta_key, so we can add filter_id for each row later
						if ( isset( $meta_keys[ $meta_key ] ) ) {
							$meta_keys[ $meta_key ][] = $element['filter_id'];
						} else {
							$meta_keys[ $meta_key ] = [ $element['filter_id'] ];
						}
					}

					if ( empty( $meta_keys ) ) {
						continue 2;
					}

					// Generate rows for each meta_key
					foreach ( $meta_keys as $meta_key => $filter_ids ) {
						// Check if this meta_key exists on $post_id
						if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
							continue;
						}

						$rows_for_this_meta_key = self::generate_custom_field_index_rows( [ $post_id ], $meta_key );

						// Build $rows_to_insert
						if ( ! empty( $rows_for_this_meta_key ) && ! empty( $filter_ids ) ) {
							// Add filter_id to each row, row is the standard template, do not overwrite it. insert rows_to_insert instead after foreach loop
							foreach ( $filter_ids as $filter_id ) {
								$rows_to_insert = array_merge(
									$rows_to_insert,
									array_map(
										function( $row ) use ( $filter_id ) {
											$row['filter_id'] = $filter_id;

											return $row;
										},
										$rows_for_this_meta_key
									)
								);
							}
						}

						// Remove rows related to this filter_id and post_id
						foreach ( $filter_ids as $filter_id ) {
							self::remove_index_rows(
								[
									'filter_id' => $filter_id,
									'object_id' => $post_id,
								]
							);
						}

					}

					break;

				default:
				case 'taxonomy|xxx':
					// explode the key
					$keys            = explode( '|', $source );
					$filter_source   = $keys[0] ?? false;
					$filter_taxonomy = $keys[1] ?? false;

					if ( ! $filter_source || ! $filter_taxonomy ) {
						continue 2;
					}

					$rows_for_this_taxonomy = self::generate_taxonomy_index_rows( [ $post_id ], $filter_taxonomy );

					// Add filter_id to each row, filter_ids are inside $elements
					$filter_ids = array_column( $elements, 'filter_id' );

					// Build $rows_to_insert
					if ( ! empty( $rows_for_this_taxonomy ) && ! empty( $filter_ids ) ) {
						foreach ( $filter_ids as $filter_id ) {
							// Add filter_id to each row, row is the standard template, do not overwrite it. insert rows_to_insert instead after foreach loop
							$rows_to_insert = array_merge(
								$rows_to_insert,
								array_map(
									function( $row ) use ( $filter_id ) {
										$row['filter_id'] = $filter_id;

										return $row;
									},
									$rows_for_this_taxonomy
								)
							);
						}
					}

					// Remove rows related to this filter_id and post_id
					foreach ( $filter_ids as $filter_id ) {
						self::remove_index_rows(
							[
								'filter_id' => $filter_id,
								'object_id' => $post_id,
							]
						);
					}

					break;
			}

			// Insert rows into database
			if ( ! empty( $rows_to_insert ) ) {
				self::insert_index_rows( $rows_to_insert );
			}
		}

	}

	/**
	 * Update indexed records when a term is amended (slug, name)
	 */
	public function edited_term( $term_id, $tt_id, $taxonomy ) {

		// Get all indexable and active filter elements from element table
		$indexable_elements = $this->get_elements_from_element_table(
			[
				'indexable' => 1,
				'status'    => 1
			]
		);

		if ( empty( $indexable_elements ) ) {
			return;
		}

		// Only get filter elements that use taxonomy as filter source and filter taxonomy is the same as $taxonomy
		$taxonomy_elements = array_filter(
			$indexable_elements,
			function( $element ) use ( $taxonomy ) {
				$filter_settings = json_decode( $element['settings'], true );
				$filter_source   = $filter_settings['filterSource'] ?? false;
				$filter_taxonomy = $filter_settings['filterTaxonomy'] ?? false;

				if ( $filter_source !== 'taxonomy' || $filter_taxonomy !== $taxonomy ) {
					return false;
				}

				return true;
			}
		);

		if ( empty( $taxonomy_elements ) ) {
			return;
		}

		global $wpdb;
		$table_name    = self::get_table_name();
		$placeholders  = array_fill( 0, count( $taxonomy_elements ), '%s' );
		$placeholders  = implode( ',', $placeholders );
		$term          = get_term( $term_id, $taxonomy );
		$value         = $term->slug;
		$display_value = $term->name;
		$filter_ids    = array_column( $taxonomy_elements, 'filter_id' );

		// Update index table
		$query = "UPDATE {$table_name}
		SET filter_value = %s, filter_value_display = %s
		WHERE filter_id IN ($placeholders) AND filter_value_id = %d";

		$wpdb->query(
			$wpdb->prepare(
				$query,
				array_merge( [ $value, $display_value ], $filter_ids, [ $term_id ] )
			)
		);
	}

	/**
	 * Update indexed records when a term is deleted
	 */
	public function delete_term( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		// Get all indexable and active filter elements from element table
		$indexable_elements = $this->get_elements_from_element_table(
			[
				'indexable' => 1,
				'status'    => 1
			]
		);

		if ( empty( $indexable_elements ) ) {
			return;
		}

		// Only get filter elements that use taxonomy as filter source and filter taxonomy is the same as $taxonomy
		$taxonomy_elements = array_filter(
			$indexable_elements,
			function( $element ) use ( $taxonomy ) {
				$filter_settings = json_decode( $element['settings'], true );
				$filter_source   = $filter_settings['filterSource'] ?? false;
				$filter_taxonomy = $filter_settings['filterTaxonomy'] ?? false;

				if ( $filter_source !== 'taxonomy' || $filter_taxonomy !== $taxonomy ) {
					return false;
				}

				return true;
			}
		);

		if ( empty( $taxonomy_elements ) ) {
			return;
		}

		global $wpdb;
		$table_name   = self::get_table_name();
		$filter_ids   = array_column( $taxonomy_elements, 'filter_id' );
		$placeholders = array_fill( 0, count( $taxonomy_elements ), '%s' );
		$placeholders = implode( ',', $placeholders );

		// Remove rows related to this term_id
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE filter_id IN ({$placeholders}) AND filter_value_id = %d",
				array_merge( $filter_ids, [ $term_id ] )
			)
		);
	}

}
