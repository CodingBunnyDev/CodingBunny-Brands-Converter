<?php
/**
* Plugin Name: CodingBunny Brands Converter
* Plugin URI:  https://coding-bunny.com/woocommerce-bulk-edit/
* Description: An add-on for CodingBunny Bulk Edit for WooCommerce to converts product attributes or taxonomies to the official Brand taxonomy.
* Version:     1.1.0
* Requires at least: 6.0
* Requires PHP: 8.0
* Author:      CodingBunny
* Author URI:  https://coding-bunny.com
* Text Domain: coding-bunny-brands-converter
* Domain Path: /languages
* License: GNU General Public License v3.0 or later
* WC tested up to: 10.4
* Requires Plugins: woocommerce, coding-bunny-bulk-edit
* Update URI:  false
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CBBCW_VERSION', '1.1.0' );
define( 'CBBCW_PLUGIN_FILE', __FILE__ );
define( 'CBBCW_PLUGIN_DIR', untrailingslashit( dirname( __FILE__ ) ) );

add_action('deactivated_plugin', function($plugin) {
	if ($plugin === 'coding-bunny-bulk-edit/coding-bunny-bulk-edit.php') {
		deactivate_plugins(plugin_basename(__FILE__));
		add_action('admin_notices', function() {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__('Brands Converter has been deactivated because CodingBunny Bulk Edit is no longer active.', 'coding-bunny-brands-converter')
					. '</p></div>';
		});
	}
});

add_action('plugins_loaded', function() {

	if (!class_exists('CodingBunnyBulkEdit') ) {
		if (is_admin()) {
			add_action('admin_notices', function() {
				echo '<div class="notice notice-error"><p>'
					. esc_html__('Brands Converter requires CodingBunny Bulk Edit to be installed and active. Please install and activate it first.', 'coding-bunny-brands-converter')
						. '</p></div>';
			});
			add_action('admin_init', function() {
				if (
				current_user_can('activate_plugins') &&
					is_plugin_active(plugin_basename(__FILE__))
				) {
					deactivate_plugins(plugin_basename(__FILE__));
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if (isset($_GET['activate'])) {
						unset($_GET['activate']);
					}
				}
			});
		}
		return;
	}
	class CBBCW_BrandsConverter {

		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'init', array( $this, 'register_brand_taxonomy' ), 5 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}

		public function load_textdomain() {
			// phpcs:ignore
			load_plugin_textdomain( 'coding-bunny-brands-converter', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

		public function register_brand_taxonomy() {
			if ( ! taxonomy_exists( 'product_brand' ) ) {
				register_taxonomy( 'product_brand', array( 'product' ), array(
					'hierarchical'      => false,
					'label'             => __( 'Product Brand', 'coding-bunny-brands-converter' ),
					'rewrite'           => array( 'slug' => 'product-brand' ),
					'show_admin_column' => true,
					'show_in_nav_menus' => true,
					'public'            => true,
					'show_ui'           => true,
				));
			}
		}

		public function add_admin_menu() {
			add_submenu_page(
			'coding-bunny-bulk-edit',
			__( 'Brands Converter', 'coding-bunny-brands-converter' ),
			__( 'Brands Converter', 'coding-bunny-brands-converter' ),
			'manage_woocommerce',
			'cbbcw-brands-converter',
			array( $this, 'settings_page' )
		);
	}

	public function enqueue_admin_assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'cbbcw-brands-converter' ) {
			$p = plugin_dir_path(__FILE__).'assets/css/cbbcw-styles.css'; $u = plugin_dir_url(__FILE__).'assets/css/cbbcw-styles.css'; $v = file_exists($p) ? filemtime($p) : null;
			wp_enqueue_style('cbbcw-admin-style', $u, array(), $v);
		}
	}

	public function get_product_taxonomies() {
		$taxonomies = get_object_taxonomies( 'product', 'objects' );
		unset( $taxonomies['product_type'], $taxonomies['product_brand'] );
		$custom_taxonomies = array_filter( $taxonomies, function( $tax ) {
			return $tax->public && ( empty( $tax->_builtin ) || 0 === strpos( $tax->name, 'pa_' ) );
		} );
		$attributes = $this->get_all_woocom_attributes();
		foreach ( $attributes as $slug => $label ) {
			if ( !isset( $custom_taxonomies[ $slug ] ) ) {
				$custom_taxonomies[ $slug ] = (object) array(
					'labels' => (object) array(
						'name' => $label
					),
					'name' => $slug
				);
			}
		}
		foreach ( $custom_taxonomies as $slug => $tax ) {
			$terms = get_terms( array(
				'taxonomy' => $slug,
				'hide_empty' => false,
				'fields' => 'ids',
			) );
			$custom_taxonomies[ $slug ]->term_count = $terms && !is_wp_error($terms) ? count($terms) : 0;
		}
		return $custom_taxonomies;
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'coding-bunny-brands-converter' ) );
		}
		$taxonomies = $this->get_product_taxonomies();
		$message = '';
		$message_type = 'info';
		$selected_tax = '';
		$selected_term_ids = array();

		if (
		isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD']
			&& isset($_POST['cbbcw_nonce'])
				&& check_admin_referer( 'cbbcw_mapper_action', 'cbbcw_nonce' )
		) {
			$from_tax = isset($_POST['taxonomy']) ? sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) ) : '';
			$selected_tax = $from_tax;
			$selected_term_ids = array();
			if ( isset( $_POST['term_ids'] ) && is_array( $_POST['term_ids'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw_terms = wp_unslash( $_POST['term_ids'] );
				$selected_term_ids = array_filter(
				array_map( 'intval', (array) $raw_terms ),
				function( $v ) { return is_numeric($v) && $v > 0; }
			);
		}

		if ( $from_tax && taxonomy_exists( $from_tax ) ) {
			if ( isset( $_POST['convert_terms'] ) && !empty($selected_term_ids) ) {
				$r = $this->migrate_to_brand_terms( $from_tax, $selected_term_ids );
				$message = $r['message'];
				$message_type = $r['success'] ? 'success' : 'error';
			}
		} else {
			$message = __( 'Invalid taxonomy selected.', 'coding-bunny-brands-converter' );
			$message_type = 'error';
		}
	}

	?>
	<?php if ( $message ) : ?>
		<div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>
	<div class="wrap cbbcw-dashboard">
		<h1 class="screen-reader-text">CodingBunny Brands Converter</h1>
		<div class="cbbcw-header">
			<?php $logo_url = plugins_url( 'assets/images/logo.svg', WP_PLUGIN_DIR . '/coding-bunny-bulk-edit/coding-bunny-bulk-edit.php' ); ?>
			<div class="cbbcw-header-left">
				<img src="<?php echo esc_url( $logo_url ); ?>"
				alt="<?php echo esc_attr__( 'CodingBunny logo', 'coding-bunny-brands-converter' ); ?>"
				class="cbbcw-logo" />
				<div class="cbbcw-title">
					<p>
						<?php esc_html_e( 'CodingBunny Brands Converter', 'coding-bunny-brands-converter' ); ?>
						<span class="cbbcw-version">
							v<?php echo defined( 'CBBCW_VERSION' ) ? esc_html( CBBCW_VERSION ) : ''; ?>
						</span>
					</p>
				</div>
			</div>
		</div>
		<div class="cbbcw-section">
			<form method="post" class="cbbcw-form" id="cbbcw-form">
				<?php wp_nonce_field( 'cbbcw_mapper_action', 'cbbcw_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="cbbcw-taxonomy"><?php esc_html_e( 'Choose BRAND taxonomy/attribute', 'coding-bunny-brands-converter' ); ?></label></th>
						<td>
							<select id="cbbcw-taxonomy" name="taxonomy" required onchange="this.form.submit();">
								<option value=""><?php esc_html_e( '-- Select --', 'coding-bunny-brands-converter' ); ?></option>
								<?php foreach ( $taxonomies as $slug => $tax ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $selected_tax, $slug ); ?>>
										<?php
										printf(
										'%s (%s) - %d %s',
										esc_html( $tax->labels->name ),
										esc_html( $slug ),
										intval( $tax->term_count ),
										esc_html( _n( 'term', 'terms', intval( $tax->term_count ), 'coding-bunny-brands-converter' ) )
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php if ( $selected_tax && taxonomy_exists($selected_tax) ) :
				$terms = get_terms( array(
					'taxonomy'   => $selected_tax,
					'hide_empty' => false,
				) );
				$is_hierarchical = is_taxonomy_hierarchical($selected_tax);
				if ( $terms && !is_wp_error( $terms ) ):
					?>
					<h4 style="margin-top:25px;"><?php esc_html_e('Select terms to convert into brand:', 'coding-bunny-brands-converter'); ?></h4>
					<table class="cbbcw-terms-table">
						<thead>
							<tr>
								<th>
									<input type="checkbox" id="cbbcw-select-all" style="transform:scale(1.2);" title="<?php esc_attr_e('Select all', 'coding-bunny-brands-converter'); ?>">
								</th>
								<th><?php esc_html_e('Term Name', 'coding-bunny-brands-converter'); ?></th>
								<th><?php esc_html_e('Slug', 'coding-bunny-brands-converter'); ?></th>
								<th><?php esc_html_e('Products assigned', 'coding-bunny-brands-converter'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							if($is_hierarchical) {
								$term_tree = $this->build_term_tree($terms);
								$this->render_hierarchical_terms($term_tree, 0, $selected_term_ids);
							} else {
								foreach($terms as $term) {
									echo '<tr>';
									echo '<td>
										<input type="checkbox"
									class="cbbcw-term-checkbox"
									name="term_ids[]"
									value="' . esc_attr($term->term_id) . '"'
									. (in_array($term->term_id, $selected_term_ids) ? ' checked' : '') .
										'/>
									</td>';
									echo '<td>'.esc_html($term->name).'</td>';
									echo '<td>'.esc_html($term->slug).'</td>';
									echo '<td>'.intval($term->count).'</td>';
									echo '</tr>';
								}
							}
							?>
						</tbody>
					</table>
					<p>
						<input type="submit" name="convert_terms"
						class="button button-primary"
						value="<?php esc_attr_e( 'Convert', 'coding-bunny-brands-converter' ); ?>" />
					</p>
					<script>
						document.addEventListener("DOMContentLoaded", function() {
							var selectAll = document.getElementById("cbbcw-select-all");
							var checkboxes = document.querySelectorAll(".cbbcw-term-checkbox");
							if (selectAll && checkboxes.length) {
								selectAll.addEventListener("change", function() {
									for (var i = 0; i < checkboxes.length; i++) {
										checkboxes[i].checked = selectAll.checked;
									}
								});
							}
						});
						</script>
					<?php endif; endif; ?>
				</form>
				<div class="cbbcw-warning">
					<strong><?php esc_html_e( 'Warning:', 'coding-bunny-brands-converter' ); ?></strong>
					<?php esc_html_e( 'This operation is irreversible. The selected terms will be added as brands to the products. The original terms must be deleted manually. ', 'coding-bunny-brands-converter' ); ?>
				</div>
			</div>
			<?php
		}

		private function build_term_tree($terms) {
			$children = array();
			$term_items = array();
			foreach ($terms as $term) {
				$term_items[$term->term_id] = $term;
				$parent = $term->parent ? $term->parent : 0;
				$children[$parent][] = $term->term_id;
			}
			return array('terms' => $term_items, 'tree' => $children);
		}

		private function render_hierarchical_terms($term_tree, $parent = 0, $selected_term_ids = array(), $depth = 0) {
			if ( isset($term_tree['tree'][$parent]) ) {
				foreach ( $term_tree['tree'][$parent] as $term_id ) {
					$term = $term_tree['terms'][$term_id];
					$indent = str_repeat('&nbsp;&nbsp;&mdash;&nbsp;', $depth);
					echo '<tr>';
					echo '<td>
						<input type="checkbox"
					class="cbbcw-term-checkbox"
					name="term_ids[]"
					value="' . esc_attr($term->term_id) . '"'
					. (in_array($term->term_id, $selected_term_ids) ? ' checked' : '') .
						'/>
					</td>';
					echo '<td>' . esc_html( $indent . $term->name ) . '</td>';
					echo '<td>' . esc_html($term->slug) . '</td>';
					echo '<td>' . intval($term->count) . '</td>';
					echo '</tr>';
					$this->render_hierarchical_terms($term_tree, $term->term_id, $selected_term_ids, $depth + 1);
				}
			}
		}

		public function get_all_woocom_attributes() {
			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				$cache_key = 'cbbcw_woo_attrs_wcapi';
				$attributes = wp_cache_get( $cache_key, 'cbbcw' );
				if ( false !== $attributes ) {
					return $attributes;
				}
				$attribute_taxonomies = wc_get_attribute_taxonomies();
				$attributes = array();
				if ( $attribute_taxonomies ) {
					foreach ( $attribute_taxonomies as $attr ) {
						$tax_name = wc_attribute_taxonomy_name( $attr->attribute_name );
						$label = $attr->attribute_label ? $attr->attribute_label : $attr->attribute_name;
						$attributes[ $tax_name ] = $label;
					}
				}
				wp_cache_set( $cache_key, $attributes, 'cbbcw', 12 * HOUR_IN_SECONDS );
				return $attributes;
			}
			global $wpdb;
			$cache_key = 'cbbcw_woo_attrs_db';
			$cached = wp_cache_get( $cache_key, 'cbbcw' );
			if ( false !== $cached ) {
				return $cached;
			}
			$attributes = array();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$results = $wpdb->get_results( "SELECT attribute_name, attribute_label FROM {$wpdb->prefix}woocommerce_attribute_taxonomies" );
			if ( $results ) {
				foreach ( $results as $attr ) {
					$tax_name = 'pa_' . $attr->attribute_name;
					$label = $attr->attribute_label ? $attr->attribute_label : $attr->attribute_name;
					$attributes[ $tax_name ] = $label;
				}
			}
			wp_cache_set( $cache_key, $attributes, 'cbbcw', 12 * HOUR_IN_SECONDS );
			return $attributes;
		}

		public function migrate_to_brand_terms( $from_tax, $term_ids ) {
			if(empty($term_ids)) {
				return array(
					'success' => false,
					'message' => __( 'No terms selected.', 'coding-bunny-brands-converter' ),
				);
			}
			$terms = get_terms( array(
				'taxonomy'   => $from_tax,
				'hide_empty' => false,
				'include'    => $term_ids,
			) );
			if ( ! $terms || is_wp_error( $terms ) ) {
				return array(
					'success' => false,
					'message' => __( 'No terms found in selected taxonomy.', 'coding-bunny-brands-converter' ),
				);
			}
			$created_terms = 0;
			$linked_products = 0;

			foreach ( $terms as $term ) {
				$brand_term = get_term_by( 'slug', $term->slug, 'product_brand' );
				if ( ! $brand_term ) {
					$brand = wp_insert_term( $term->name, 'product_brand', array(
						'description' => $term->description,
						'slug'        => $term->slug,
					) );
					if ( ! is_wp_error( $brand ) ) {
						$brand_term_id = $brand['term_id'];
						$created_terms++;
					} else {
						continue;
					}
				} else {
					$brand_term_id = $brand_term->term_id;
				}
				$query = new WP_Query( array(
					'post_type'      => 'product',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'tax_query'      => array( array(
						'taxonomy' => $from_tax,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					)),
				) );
				if ( $query->have_posts() ) {
					foreach ( $query->posts as $pid ) {
						wp_set_object_terms( $pid, (int)$brand_term_id, 'product_brand', true );
						$linked_products++;
					}
				}
			}
			return array(
				'success' => true,
				'message' => sprintf(
					// translators: %1$d is the number of terms processed, %2$d is the number of products linked
					__( '%1$d terms processed. %2$d product(s) linked to related brands. Mapping complete!', 'coding-bunny-brands-converter' ),
					$created_terms, $linked_products
				),
			);
		}
	}

	add_action( 'before_woocommerce_init', function() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	} );

	new CBBCW_BrandsConverter();
});