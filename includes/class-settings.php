<?php
/**
 * Network settings for cross-site search.
 *
 * @package Soderlind\Plugin\LoupeCrossSiteSearch
 */

declare(strict_types=1);

namespace Soderlind\Plugin\LoupeCrossSiteSearch;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Stores and renders the network-level configuration: the hub site, which sites
 * participate, the combined-index language, and which post types are covered.
 */
class Settings {

	public const OPTION = 'loupe_cross_site_settings';

	public function __construct() {
		add_action( 'network_admin_menu', [ $this, 'add_menu' ] );
		add_action( 'network_admin_edit_' . self::OPTION, [ $this, 'save' ] );
	}

	/**
	 * Merged settings with defaults.
	 *
	 * @return array{hub_blog_id:int,mode:string,sites:int[],language:string,post_types:string[]}
	 */
	public static function get(): array {
		$defaults = [
			'hub_blog_id' => get_main_site_id(),
			'mode'        => 'all',
			'sites'       => [],
			'language'    => 'en',
			'post_types'  => [ 'post', 'page' ],
		];
		$saved = get_site_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		$merged                = array_merge( $defaults, $saved );
		$merged['hub_blog_id'] = (int) $merged['hub_blog_id'];
		$merged['sites']       = array_values( array_map( 'intval', (array) $merged['sites'] ) );
		$merged['post_types']  = array_values( array_filter( array_map( 'sanitize_key', (array) $merged['post_types'] ) ) );
		$merged['mode']        = in_array( $merged['mode'], [ 'all', 'allowlist', 'blocklist' ], true ) ? $merged['mode'] : 'all';
		return $merged;
	}

	public static function get_hub_blog_id(): int {
		return self::get()['hub_blog_id'];
	}

	public static function get_language(): string {
		$lang = self::get()['language'];
		return preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'en';
	}

	/**
	 * @return string[]
	 */
	public static function get_post_types(): array {
		$types = self::get()['post_types'];
		return empty( $types ) ? [ 'post', 'page' ] : $types;
	}

	public static function get_mode(): string {
		return self::get()['mode'];
	}

	/**
	 * @return int[]
	 */
	public static function get_configured_sites(): array {
		return self::get()['sites'];
	}

	public function add_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'Cross-Site Search', 'loupe-cross-site-search' ),
			__( 'Cross-Site Search', 'loupe-cross-site-search' ),
			'manage_network_options',
			'loupe-cross-site-search',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$settings   = self::get();
		$sites      = get_sites( [ 'number' => 0, 'orderby' => 'path' ] );
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$action     = add_query_arg( 'action', self::OPTION, network_admin_url( 'edit.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Loupe Cross-Site Search', 'loupe-cross-site-search' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'loupe-cross-site-search' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<?php wp_nonce_field( self::OPTION . '-save', self::OPTION . '_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lcss_hub"><?php esc_html_e( 'Hub site', 'loupe-cross-site-search' ); ?></label></th>
						<td>
							<select name="hub_blog_id" id="lcss_hub">
								<?php foreach ( $sites as $site ) : ?>
									<option value="<?php echo (int) $site->blog_id; ?>" <?php selected( (int) $site->blog_id, $settings['hub_blog_id'] ); ?>>
										<?php echo esc_html( $site->blogname . ' (' . untrailingslashit( $site->domain . $site->path ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The site that exposes the cross-site search REST endpoint.', 'loupe-cross-site-search' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Participation', 'loupe-cross-site-search' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="mode" value="all" <?php checked( 'all', $settings['mode'] ); ?>> <?php esc_html_e( 'All public sites', 'loupe-cross-site-search' ); ?></label><br>
								<label><input type="radio" name="mode" value="allowlist" <?php checked( 'allowlist', $settings['mode'] ); ?>> <?php esc_html_e( 'Only the sites selected below (allowlist)', 'loupe-cross-site-search' ); ?></label><br>
								<label><input type="radio" name="mode" value="blocklist" <?php checked( 'blocklist', $settings['mode'] ); ?>> <?php esc_html_e( 'All public sites except those selected below (blocklist)', 'loupe-cross-site-search' ); ?></label>
							</fieldset>
							<p style="margin-top:8px;">
								<select name="sites[]" multiple size="6" style="min-width:320px;">
									<?php foreach ( $sites as $site ) : ?>
										<option value="<?php echo (int) $site->blog_id; ?>" <?php echo in_array( (int) $site->blog_id, $settings['sites'], true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $site->blogname . ' (' . untrailingslashit( $site->domain . $site->path ) . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lcss_lang"><?php esc_html_e( 'Index language', 'loupe-cross-site-search' ); ?></label></th>
						<td>
							<input type="text" name="language" id="lcss_lang" value="<?php echo esc_attr( $settings['language'] ); ?>" size="4" maxlength="2">
							<p class="description"><?php esc_html_e( 'Two-letter language code used to tokenize the combined index (e.g. en, nb, de).', 'loupe-cross-site-search' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'loupe-cross-site-search' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $post_types as $pt ) : ?>
									<label style="display:inline-block;margin-right:12px;">
										<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php echo in_array( $pt->name, $settings['post_types'], true ) ? 'checked' : ''; ?>>
										<?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Only these post types are mirrored into the combined index. Reindex after changing.', 'loupe-cross-site-search' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<p><em><?php esc_html_e( 'After changing participation, language, or post types, run: wp loupe-cross-site reindex', 'loupe-cross-site-search' ); ?></em></p>
		</div>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'loupe-cross-site-search' ) );
		}
		check_admin_referer( self::OPTION . '-save', self::OPTION . '_nonce' );

		$value = [
			'hub_blog_id' => isset( $_POST['hub_blog_id'] ) ? (int) $_POST['hub_blog_id'] : get_main_site_id(),
			'mode'        => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'all',
			'sites'       => isset( $_POST['sites'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['sites'] ) ) : [],
			'language'    => isset( $_POST['language'] ) ? strtolower( substr( sanitize_key( wp_unslash( $_POST['language'] ) ), 0, 2 ) ) : 'en',
			'post_types'  => isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : [ 'post', 'page' ],
		];

		update_site_option( self::OPTION, $value );

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'loupe-cross-site-search', 'updated' => '1' ],
			network_admin_url( 'settings.php' )
		) );
		exit;
	}
}
