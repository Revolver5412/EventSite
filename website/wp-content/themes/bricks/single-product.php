<?php
/*
 * This overrides the default WooCommerce file
 * @version     1.6.4
 */

get_header();

if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();

		$bricks_data = Bricks\Helpers::get_bricks_data( get_the_ID(), 'content' );

		// Bricks data
		if ( $bricks_data ) {

			global $product;
			// @since 1.8.1 - Add standard WooCommerce product classes to the container
			$attributes['class'] = (array) wc_get_product_class( '', $product );
			$html_after_begin    = '';
			$bricks_data_json    = wp_json_encode( $bricks_data );

			/**
			 * Auto render woo notice if not using Bricks WooCommerce "Notice" element
			 *
			 * @since 1.8.1
			 */
			if ( ! Bricks\Woocommerce::use_bricks_woo_notice_element() ) {
				$bricks_has_woo_notice_do_action = strpos( $bricks_data_json, '{do_action:woocommerce_before_single_product}' ) !== false;

				/**
				 * Render woo notice if not added via Bricks {do_action:woocommerce_before_single_product} in single product template
				 *
				 * @since 1.7
				 */
				if ( ! $bricks_has_woo_notice_do_action ) {
					$html_after_begin = '<div class="woocommerce-notices-wrapper brxe-container">' . wc_print_notices( true ) . '</div>';
				}
			}

			/**
			 * Check if {do_action:woocommerce_single_product_summary} is used in the template
			 *
			 * If not, generate structured data.
			 *
			 * @since 1.9.8
			 */
			$bricks_has_woo_summary_do_action = strpos( $bricks_data_json, '{do_action:woocommerce_single_product_summary}' ) !== false;

			if ( ! $bricks_has_woo_summary_do_action && is_a( WC()->structured_data, 'WC_Structured_Data' ) ) {
				// Generate structured data, by default it's generated on the "woocommerce_single_product_summary" action
				WC()->structured_data->generate_product_data( $product );
			}

			Bricks\Frontend::render_content( $bricks_data, $attributes, $html_after_begin );
		}

		// Default WooCommerce single product template
		elseif ( function_exists( 'wc_get_template_part' ) ) {
			do_action( 'woocommerce_before_main_content' );

			wc_get_template_part( 'content', 'single-product' );

			do_action( 'woocommerce_after_main_content' );
		}

		// Default content
		else {
			the_content();
		}
	}
}

get_footer();
