<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Element_Post_Reading_Time extends Element {
	public $category = 'single';
	public $name     = 'post-reading-time';
	public $icon     = 'ti-time';
	public $scripts  = [ 'bricksPostReadingTime' ];

	public function get_label() {
		return esc_html__( 'Reading time', 'bricks' );
	}

	public function set_controls() {
		$this->controls['contentSelector'] = [
			'label'       => esc_html__( 'Content selector', 'bricks' ),
			'type'        => 'text',
			'placeholder' => '.brxe-post-content',
			'description' => esc_html__( 'Fallback', 'bricks' ) . ': #brx-content',
		];

		$this->controls['prefix'] = [
			'label'   => esc_html__( 'Prefix', 'bricks' ),
			'type'    => 'text',
			'inline'  => true,
			'default' => 'Reading time: ',
		];

		$this->controls['suffix'] = [
			'label'   => esc_html__( 'Suffix', 'bricks' ),
			'type'    => 'text',
			'inline'  => true,
			'default' => ' minutes',
		];

		$this->controls['wordsPerMinute'] = [
			'label'       => esc_html__( 'Words per minutes', 'bricks' ),
			'type'        => 'number',
			'inline'      => true,
			'large'       => true,
			'placeholder' => 200,
		];
	}

	public function render() {
		$settings = $this->settings;
		$prefix   = $settings['prefix'] ?? '';
		$suffix   = $settings['suffix'] ?? '';

		/**
		 * STEP: Calculate reading time inside query loop
		 *
		 * If no content selector is set, calculate reading time based on the post content.
		 *
		 * @since 1.10
		 */
		if ( Query::is_any_looping() && ! isset( $settings['contentSelector'] ) ) {
			$post_content     = get_post_field( 'post_content', get_the_ID() );
			$word_count       = str_word_count( strip_tags( $post_content ) );
			$words_per_minute = $settings['wordsPerMinute'] ?? 200;
			$reading_time     = ceil( $word_count / $words_per_minute );

			$text = $prefix . $reading_time . $suffix;

			echo "<div {$this->render_attributes( '_root' )}>$text</div>";

			return;
		}

		// STEP: Calculate reading time of content on the page outside any query loop (via JS)
		if ( $prefix ) {
			$this->set_attribute( '_root', 'data-prefix', $prefix );
		}

		if ( $suffix ) {
			$this->set_attribute( '_root', 'data-suffix', $suffix );
		}

		$this->set_attribute( '_root', 'data-wpm', $settings['wordsPerMinute'] ?? 200 );

		if ( ! empty( $settings['contentSelector'] ) ) {
			$this->set_attribute( '_root', 'data-content-selector', $settings['contentSelector'] );
		}

		echo "<div {$this->render_attributes( '_root' )}></div>";
	}
}
