<?php
namespace Bricks\Integrations\Dynamic_Data;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Providers {
	/**
	 * Holds the providers
	 *
	 * @var array
	 */
	private $providers_keys = [];

	/**
	 * Holds the providers instances
	 *
	 * @var array
	 */
	private static $providers = [];

	/**
	 * Holds the tags instances
	 *
	 * @var array
	 */
	private $tags = [];

	public function __construct( $providers ) {
		$this->providers_keys = $providers;

		// @since 1.10
		add_filter( 'bricks/dynamic_data/format_value', [ $this, 'handle_fallback' ], 10, 5 );
	}

	public static function register( $providers = [] ) {
		$instance = new self( $providers );

		// Register providers (priority 10000 due to CMB2 priority)
		add_action( 'init', [ $instance, 'register_providers' ], 10000 );

		// Register tags on init after register_providers (@since 1.9.8)
		add_action( 'init', [ $instance, 'register_tags' ], 10001 );

		// Register providers during WP REST API call (priority 7 to run before register_tags() on WP REST API)
		add_action( 'rest_api_init', [ $instance, 'register_providers' ], 7 );

		// Hook 'init' doesn't run on REST API calls so we need this to register the tags when rendering elements (needed for Posts element) or fetching dynamic data content
		add_action( 'rest_api_init', [ $instance, 'register_tags' ], 8 );

		// Register tags before wp_enqueue_scripts (but not before wp to get the post custom fields)
		// Priority = 8 to run before Setup::init_control_options
		// Not in use (@since 1.9.8), Register on 'init' hook above (#86bw2ytax)
		// add_action( 'wp', [ $instance, 'register_tags' ], 8 );

		// Hook "wp" doesn't run on AJAX/REST API calls so we need this to register the tags when rendering elements (needed for Posts element) or fetching dynamic data content
		// Not in use (@since 1.9.8), Register on 'init' hook above (#86bw2ytax)
		// add_action( 'admin_init', [ $instance, 'register_tags' ], 8 );

		add_filter( 'bricks/dynamic_tags_list', [ $instance, 'add_tags_to_builder' ] );

		// Render dynamic data in builder too (when template preview post ID is set)
		add_filter( 'bricks/frontend/render_data', [ $instance, 'render' ], 10, 2 );

		add_filter( 'bricks/dynamic_data/render_content', [ $instance, 'render' ], 10, 3 );

		add_filter( 'bricks/dynamic_data/render_tag', [ $instance, 'get_tag_value' ], 10, 3 );
	}

	/**
	 * Get a registered provider
	 *
	 * @since 1.9.9
	 */
	public static function get_registered_provider( $provider ) {
		return self::$providers[ $provider ] ?? null;
	}

	public function register_providers() {
		/**
		 * Don't register providers in WP admin
		 *
		 * Interferes with default ACF logic of displaying ACF fields in the backend.
		 *
		 * @see #86byqukg8, #86bx5f831
		 *
		 * @since 1.9.9
		 */
		if ( is_admin() && ! bricks_is_ajax_call() && ! bricks_is_rest_call() ) {
			return;
		}

		foreach ( $this->providers_keys as $provider ) {
			$classname = 'Bricks\Integrations\Dynamic_Data\Providers\Provider_' . str_replace( ' ', '_', ucwords( str_replace( '-', ' ', $provider ) ) );

			if ( $classname::load_me() ) {
				self::$providers[ $provider ] = new $classname( str_replace( '-', '_', $provider ) );
			}
		}
	}

	public function register_tags() {
		foreach ( self::$providers as $key => $provider ) {
			$this->tags = array_merge( $this->tags, $provider->get_tags() );
		}
	}

	public function get_tags() {
		return $this->tags;
	}

	/**
	 * Adds tags to the tags picker list (used in the builder)
	 *
	 * @param array $tags
	 * @return array
	 */
	public function add_tags_to_builder( $tags ) {
		$list = $this->get_tags();

		foreach ( $list as $tag ) {
			if ( isset( $tag['deprecated'] ) ) {
				continue;
			}

			$tags[] = [
				'name'  => $tag['name'],
				'label' => $tag['label'],
				'group' => $tag['group']
			];
		}

		return $tags;
	}

	/**
	 * Dynamic tag exists in $content: Replaces dynamic tag with requested data
	 *
	 * @param string  $content
	 * @param WP_Post $post
	 */
	public function render( $content, $post, $context = 'text' ) {
		/**
		 * \w: Matches any word character (alphanumeric & underscore).
		 * Equivalent to [A-Za-z0-9_]
		 * "À-ÖØ-öø-ÿ" Add the accented characters
		 * "-" Needed because some post types handles are like "my-post-type"
		 * ":" Needed for extra arguments to dynamic data tags (e.g. post_excerpt:20 or wp_user_meta:my_meta_key)
		 * "|" and "," needed for the post terms like {post_terms_post_tag:sep} where sep could be a pipe or comma
		 * "(", ")" and "'" for the function arguments of the dynamic tag {echo}
		 * "@" to support email addresses as arguments of the dynamic tag {echo} #3kazphp
		 *
		 * @since 1.9.4: "u" modifier: Pattern strings are treated as UTF-8 to support Cyrillic, Arabic, etc.
		 * @since 1.10: "$", "+", "%", "#", "!", "=", "<", ">", "&", "~", "[", "]", ";" as arguments of the dynamic tag {echo}
		 *
		 * @see https://regexr.com/
		 */
		$pattern = '/{([\wÀ-ÖØ-öø-ÿ\-\s\.\/:\(\)\'@|,$%#!+=<>&~\[\];]+)}/u';

		/**
		 * Matches the echo tag pattern (#86bwebj6m)
		 *
		 * @since 1.9.8
		 */
		$echo_pattern = '/echo:([a-zA-Z0-9_]+)/';

		// Get a list of tags to exclude from the Dynamic Data logic
		$exclude_tags = apply_filters( 'bricks/dynamic_data/exclude_tags', [] );

		/**
		 * STEP: Determine how many times we need to run the DD parser
		 *
		 * Previously we ran the parser by counting the number of open curly braces in the content. (@since 1.8)
		 * But this is not reliable because the content could contain curly braces in the code elements or any shortcodes.
		 * Causing the website to load extremely slow.
		 *
		 * @since 1.8.2 (#862jyyryg)
		 */
		// Get all registered tags except the excluded ones.
		// Example: [0 => "post_title", 1 => "woo_product_price", 2 => "echo"]
		$registered_tags = array_filter(
			array_keys( $this->get_tags() ),
			function( $tag ) use ( $exclude_tags ) {
				return ! in_array( $tag, $exclude_tags );
			}
		);

		$dd_tags_in_content = [];
		$dd_tags_found      = [];
		$echo_tags_found    = [];

		// Find all dynamic data tags in the content
		preg_match_all( $pattern, $content, $dd_tags_in_content );

		$dd_tags_in_content = ! empty( $dd_tags_in_content[1] ) ? $dd_tags_in_content[1] : [];

		// Find all echo tags in the content (@since 1.9.8)
		preg_match_all( $echo_pattern, $content, $echo_tags_found );

		// Combine the dynamic data tags from the content and the echo tags (@since 1.9.8)
		if ( ! empty( $echo_tags_found[0] ) ) {
			$dd_tags_in_content = array_merge( $dd_tags_in_content, $echo_tags_found[0] );
		}

		if ( ! empty( $dd_tags_in_content ) ) {
			/**
			 * $dd_tags_in_content only matches the pattern, but some codes from Code element could match the pattern too.
			 * Example: function test() { return 'Hello World'; } will match the pattern, but it's not a dynamic data tag.
			 *
			 * Find all dynamic data tags in the content which starts with dynamic data tag from $registered_tags
			 * Cannot use array_in or array_intersect because $registered_tags only contains the tag name, somemore tags could have filters like {echo:my_function( 'Hello World' )
			 *
			 * Example: $registered_tags    = [0 => "post_title", 1 => "woo_product_price", 2 => "echo"]
			 * Example: $dd_tags_in_content = [0 => "post_title", 1 => "woo_product_price:value", 2 => "echo:my_function('Hello World')"]
			 */
			$dd_tags_found = array_filter(
				$dd_tags_in_content,
				function( $tag ) use ( $registered_tags ) {
					foreach ( $registered_tags as $all_tag ) {
						/**
						 * Skip WP custom field (starts with cf_)
						 *
						 * As Provider_Wp->get_site_meta_keys() can cause performance issues on larger sites
						 *
						 * @see #862k3f2md
						 * @since 1.8.3
						 */
						if ( strpos( $tag, 'cf_' ) === 0 ) {
							return true;
						}

						if ( strpos( $tag, $all_tag ) === 0 ) {
							return true;
						}
					}
					return false;
				}
			);
		}

		// Get the count of found dynamic data tags
		$dd_tag_count = count( $dd_tags_found );

		// STEP: Run the parser based on the count of found dynamic data tags
		for ( $i = 0; $i < $dd_tag_count; $i++ ) {
			preg_match_all( $pattern, $content, $matches );

			if ( empty( $matches[0] ) ) {
				return $content;
			}

			$run_again = false;

			foreach ( $matches[1] as $key => $match ) {
				$tag = $matches[0][ $key ];

				if ( in_array( $match, $exclude_tags ) ) {
					continue;
				}

				$value = $this->get_tag_value( $match, $post, $context );

				// Value is a WP_Error: Set value to false to avoid error in builder (#862k4cyc8)
				if ( is_a( $value, 'WP_Error' ) ) {
					$value = false;
				}

				// NOTE: Undocumented (only enable if really needed)
				$echo_everywhere = apply_filters( 'bricks/code/echo_everywhere', false );
				if ( $value && strpos( $value, '{echo:' ) !== false ) {
					if ( $echo_everywhere !== true ) {
						// Default: Stop the parser if the value contains an echo tag
						continue;
					}

					/**
					 * Certain tags might not be parsed correctly after {echo:}
					 *
					 * So we need to run the parser again later
					 *
					 * @since 1.9.9
					 */
					$run_again = true;
				}

				$content = str_replace( $tag, $value, $content );
			}

			if ( $run_again ) {
				$dd_tag_count++;
			}
		}

		return $content;
	}

	/**
	 * Get the value of a dynamic data tag
	 *
	 * @param string  $tag without curly brackets {}.
	 * @param WP_Post $post The post object.
	 * @param string  $context text, link, image.
	 */
	public function get_tag_value( $tag, $post, $context = 'text' ) {
		$parsed       = $this->parse_tag_and_args( $tag );
		$tag          = $parsed['tag'];
		$args         = $parsed['args'];
		$original_tag = $parsed['original_tag'];

		$tags = $this->get_tags();

		if ( ! array_key_exists( $tag, $tags ) ) {
			// Last resort: Try to get field content if it is a WordPress custom field
			if ( strpos( $tag, 'cf_' ) === 0 ) {
				// Use get_tag_value function in provider-wp.php (@since 1.9.8)
				return self::$providers['wp']->get_tag_value( $tag, $post, $args, $context );
			}

			/**
			 * If true, Bricks replaces not existing DD tags with an empty string
			 *
			 * true caused unwanted replacement of inline <script> & <style> tag data.
			 *
			 * Set to false @since 1.4 to render all non-matching DD tags (#2ufh0uf)
			 *
			 * https://academy.bricksbuilder.io/article/filter-bricks-dynamic_data-replace_nonexistent_tags/
			 */
			$replace_tag = apply_filters( 'bricks/dynamic_data/replace_nonexistent_tags', false );

			return $replace_tag ? '' : '{' . $original_tag . '}';
		}

		$provider = $tags[ $tag ]['provider'];

		return self::$providers[ $provider ]->get_tag_value( $tag, $post, $args, $context );
	}

	/**
	 * Parse the tag and extract arguments
	 *
	 * @param string $tag The original tag string.
	 * @return array An array containing the parsed tag and arguments.
	 *
	 * @since 1.10
	 */
	private function parse_tag_and_args( $tag ) {
		$original_tag = $tag;
		$args         = [];

		// Special case to allow using "@" as "echo:" tag parameter
		// TODO NEXT: More rebust DD argument parser (@see #86bzunbgf)
		if ( strpos( $tag, 'echo:' ) === 0 ) {
			return [
				'tag'          => 'echo',
				'args'         => [ substr( $tag, 5 ) ], // Everything after 'echo:'
				'original_tag' => $original_tag,
			];
		}

		// Check if tag has ':' or '@' indicating it has arguments
		if ( strpos( $tag, ':' ) !== false || strpos( $tag, '@' ) !== false ) {
			// If there's a ':' before the first '@', split at the first ':'
			if ( strpos( $tag, ':' ) !== false && ( strpos( $tag, '@' ) === false || strpos( $tag, ':' ) < strpos( $tag, '@' ) ) ) {
				list($tag, $args_string) = explode( ':', $tag, 2 );
			} else {
				// If there's no ':' before the first '@', the tag is before the '@'
				$args_string = $tag;
				$tag         = strtok( $tag, ' @' );
			}

			// Check if the args_string contains key-value pairs marked with '@'
			if ( strpos( $args_string, '@' ) !== false ) {
				list($standard_args, $kv_args_string) = explode( '@', $args_string, 2 );

				// Add standard arguments to the args array
				if ( ! empty( $standard_args ) ) {
					$standard_args_array = explode( ':', $standard_args );
					foreach ( $standard_args_array as $arg ) {
						$args[] = trim( $arg );
					}
				}

				// Split the key-value pairs
				$kv_pairs = explode( '@', $kv_args_string );
				foreach ( $kv_pairs as $pair ) {
					list($key, $value)    = explode( ':', $pair, 2 );
					$args[ trim( $key ) ] = trim( $value );
				}
			} else {
				// No key-value pairs, just standard arguments
				$standard_args_array = explode( ':', $args_string );
				foreach ( $standard_args_array as $arg ) {
					$args[] = trim( $arg );
				}
			}
		}

		return [
			'tag'          => $tag,
			'args'         => $args,
			'original_tag' => $original_tag
		];
	}

	/**
	 * Handle fallbacks for dynamic data tags
	 *
	 * @since 1.10
	 */
	public function handle_fallback( $value, $tag, $post_id, $filters, $context ) {
		// STEP: Check for fallback argument
		if ( empty( $value ) && isset( $filters['fallback'] ) ) {
			// Remove the single quotes and handle escaped characters
			$fallback = stripslashes( $filters['fallback'] );

			if ( substr( $fallback, 0, 1 ) === "'" && substr( $fallback, -1 ) === "'" ) {
				$fallback = substr( $fallback, 1, -1 );
			}

			return $fallback;
		}

		// STEP: Check for fallback-image arugment
		if ( empty( $value ) && isset( $filters['fallback-image'] ) ) {
			// Remove the single quotes and handle escaped characters
			$fallback_image = stripslashes( $filters['fallback-image'] );

			if ( substr( $fallback_image, 0, 1 ) === "'" && substr( $fallback_image, -1 ) === "'" ) {
				$fallback_image = substr( $fallback_image, 1, -1 );
			}

			// Check if the fallback is a numeric ID
			if ( is_numeric( $fallback_image ) ) {
				$attachment_id = intval( $fallback_image );

				if ( $context === 'image' ) {
					return [ $attachment_id ];
				}

				$image = wp_get_attachment_image( $attachment_id, 'full' );
				if ( $image ) {
					return $image;
				}
			} else {
				if ( $context === 'image' ) {
					return [ $fallback_image ];
				}

				// Assume fallback is an image URL
				return '<img src="' . esc_url( $fallback_image ) . '" />';
			}
		}

		return $value;
	}

	public static function render_tag( $tag = '', $post_id = 0, $context = 'text', $args = [] ) {
		// Support for dynamic data picker and input text (@since 1.5)
		$tag = ! empty( $tag['name'] ) ? $tag['name'] : (string) $tag;

		$tag = trim( $tag );

		// Remove all curly brackets from DD tag (@pre 1.9.9)
		// $tag = str_replace( [ '{', '}' ], '', $tag );

		// Only remove outermost curly brackets from DD tag (@since 1.9.9)
		if ( substr( $tag, 0, 1 ) === '{' && substr( $tag, -1 ) === '}' ) {
			$tag = substr( $tag, 1, -1 );
		}

		// Image is user avatar (get_avatar_url): Set the size
		if ( $context === 'image' && in_array( $tag, [ 'wp_user_picture', 'author_avatar' ] ) && isset( $args['size'] ) ) {
			$all_image_sizes = \Bricks\Setup::get_image_sizes();

			if ( ! empty( $all_image_sizes[ $args['size'] ]['width'] ) ) {
				$tag = $tag . ':' . abs( $all_image_sizes[ $args['size'] ]['width'] );
			}
		}

		$post = get_post( $post_id );

		return apply_filters( 'bricks/dynamic_data/render_tag', $tag, $post, $context );
	}

	public static function render_content( $content, $post_id = 0, $context = 'text' ) {
		// Return: Content is a flat array (Example: 'user_role' element conditions @since 1.5.6)
		if ( is_array( $content ) && isset( $content[0] ) ) {
			return $content;
		}

		// Support for dynamic data picker and input text (@since 1.5)
		$content = ! empty( $content['name'] ) ? $content['name'] : (string) $content;

		// Return: $content doesn't contain opening DD tag character '{' (@since 1.5)
		if ( strpos( $content, '{' ) === false ) {
			return $content;
		}

		// Strip slashes for DD "echo" function to allow DD preview render in builder (@since 1.5.3)
		if ( strpos( $content, '{echo:' ) !== false ) {
			$content = stripslashes( $content );
		}

		$post_id = empty( $post_id ) ? get_the_ID() : $post_id;
		$post    = get_post( $post_id );

		return apply_filters( 'bricks/dynamic_data/render_content', $content, $post, $context );
	}

	public static function get_dynamic_tags_list() {
		// NOTE: Undocumented. This allows the dynamic data providers to add their tags to the builder
		$tags = apply_filters( 'bricks/dynamic_tags_list', [] );

		return $tags;
	}
}
