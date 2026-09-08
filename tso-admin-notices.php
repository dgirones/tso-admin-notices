<?php
/**
 * Plugin Name:       TSO Admin Notices Manager
 * Description:       Hides annoying plugin notices (promotional, backup, update messages) from the WordPress admin. Notices remain recoverable via the admin bar.
 * Version:           1.0.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Tu Soporte Online
 * Author URI:        https://tusoporteonline.es
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tso-admin-notices
 * Domain Path:       /languages
 *
 * @package TSO_Admin_Notices
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TSOAN_VERSION', '1.0.0' );
define( 'TSOAN_FILE', __FILE__ );
define( 'TSOAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'TSOAN_URL', plugin_dir_url( __FILE__ ) );
define( 'TSOAN_OPTION', 'tso_admin_notices_settings' );

register_activation_hook( TSOAN_FILE, array( 'TSOAN_Manager', 'activate' ) );
register_deactivation_hook( TSOAN_FILE, array( 'TSOAN_Manager', 'deactivate' ) );
add_action( 'init', array( 'TSOAN_Manager', 'load_textdomain' ), 0 );
add_action( 'plugins_loaded', array( 'TSOAN_Manager', 'get_instance' ) );

/**
 * Main plugin class — singleton.
 *
 * @package TSO_Admin_Notices
 * @since   1.0.0
 */
final class TSOAN_Manager {

	/**
	 * Default settings.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFAULTS = array(
		'enabled'        => 1,
		'whitelist'      => array(),
		'show_admin_bar' => 1,
	);

	/**
	 * Singleton instance.
	 *
	 * @var TSOAN_Manager|null
	 */
	private static $instance = null;

	/**
	 * Merged settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings = array();

	/**
	 * Whether the early wrap pass has run.
	 *
	 * @var bool
	 */
	private $wrapped = false;

	/**
	 * Cross-hook deduplication map.
	 *
	 * @var array<string,true>
	 */
	private $wrapped_fn_keys = array();

	/**
	 * Load bundled translation catalogs (WordPress 6.1–6.4; 6.5+ JIT also works).
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		$locale = determine_locale();
		$mofile = TSOAN_PATH . 'languages/tso-admin-notices-' . $locale . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'tso-admin-notices', $mofile );
		}
	}

	/**
	 * Return singleton instance.
	 *
	 * @return TSOAN_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->maybe_migrate_option();
		$this->load_settings();

		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 1 );
		add_action( 'wp_before_admin_bar_render', array( $this, 'add_admin_bar_node' ) );

		if ( $this->is_enabled() ) {
			// Early: before most plugins add notice callbacks on admin_init.
			add_action( 'admin_init', array( $this, 'wrap_notice_callbacks' ), PHP_INT_MIN );
			// Late admin_init / admin_menu: Backuply, Rank Math, Softaculous, etc.
			add_action( 'admin_init', array( $this, 'wrap_late_pass' ), PHP_INT_MAX );
			add_action( 'admin_menu', array( $this, 'wrap_late_pass' ), PHP_INT_MAX );
			// Immediately before notice hooks fire.
			add_action( 'admin_notices', array( $this, 'wrap_late_pass' ), PHP_INT_MIN );
			add_action( 'all_admin_notices', array( $this, 'wrap_late_pass' ), PHP_INT_MIN );
			add_action( 'user_admin_notices', array( $this, 'wrap_late_pass' ), PHP_INT_MIN );
			add_action( 'network_admin_notices', array( $this, 'wrap_late_pass' ), PHP_INT_MIN );
			// Full-width slot above #wpbody-content: some update/promo nags print here.
			add_action( 'in_admin_header', array( $this, 'wrap_late_pass' ), PHP_INT_MIN );
		}
	}

	/**
	 * Migrate legacy option keys once.
	 *
	 * @return void
	 */
	private function maybe_migrate_option() {
		if ( false !== get_option( TSOAN_OPTION, false ) ) {
			return;
		}

		$legacy = get_option( 'tso_an_settings', false );
		if ( false !== $legacy && is_array( $legacy ) ) {
			add_option( TSOAN_OPTION, wp_parse_args( $legacy, self::DEFAULTS ) );
			delete_option( 'tso_an_settings' );
		}
	}

	/**
	 * Load settings.
	 *
	 * @return void
	 */
	private function load_settings() {
		$saved          = get_option( TSOAN_OPTION, array() );
		$this->settings = wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			self::DEFAULTS
		);
	}

	/**
	 * Whether filtering is enabled.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Whitelist of plugin slugs.
	 *
	 * @return array<int,string>
	 */
	private function get_whitelist() {
		$wl = isset( $this->settings['whitelist'] ) ? $this->settings['whitelist'] : array();
		return is_array( $wl ) ? $wl : array();
	}

	/**
	 * Early wrap pass.
	 *
	 * @return void
	 */
	public function wrap_notice_callbacks() {
		if ( $this->wrapped ) {
			return;
		}
		$this->wrapped = true;
		$this->wrap_all_notice_hooks();
	}

	/**
	 * Late wrap pass for callbacks registered after the early pass.
	 *
	 * @return void
	 */
	public function wrap_late_pass() {
		$this->wrap_all_notice_hooks();
	}

	/**
	 * Wrap all standard notice hooks.
	 *
	 * @return void
	 */
	private function wrap_all_notice_hooks() {
		$hooks = array( 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' );
		foreach ( $hooks as $hook ) {
			$this->wrap_hook( $hook );
		}
		// in_admin_header renders full-width above #wpbody-content; some plugins
		// print promo/update nags here. Only wrap output that looks like a notice.
		$this->wrap_hook( 'in_admin_header', true );
	}

	/**
	 * Wrap eligible callbacks on one hook.
	 *
	 * @param string $hook              Hook name.
	 * @param bool   $notice_like_only  When true (shared hooks such as
	 *                                  in_admin_header), only intercept output that
	 *                                  looks like an admin notice; pass the rest through.
	 * @return void
	 */
	private function wrap_hook( $hook, $notice_like_only = false ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $key => $cb ) {
				if ( ! empty( $cb['tsoan_wrapped'] ) ) {
					continue;
				}
				if ( $this->is_core_callback( $cb ) ) {
					continue;
				}

				$source = $this->get_callback_source( $cb );

				// Never touch this plugin's own output.
				if ( 'tso-admin-notices' === $source ) {
					continue;
				}

				// First-party TSO plugins and whitelisted plugins stay visible, but
				// are still wrapped in a marked container so the JS layer skips them.
				$keep_visible = $this->is_own_family( $source ) || $this->is_whitelisted( $source );

				$fn_key = $this->get_fn_key( $cb['function'] );
				if ( '' !== $fn_key && isset( $this->wrapped_fn_keys[ $fn_key ] ) ) {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional wrap marker.
					$wp_filter[ $hook ]->callbacks[ $priority ][ $key ]['tsoan_wrapped'] = true;
					continue;
				}
				if ( '' !== $fn_key ) {
					$this->wrapped_fn_keys[ $fn_key ] = true;
				}

				$original_fn   = $cb['function'];
				$accepted_args = isset( $cb['accepted_args'] ) ? max( 0, (int) $cb['accepted_args'] ) : 1;

				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional wrap of notice callbacks.
				$wp_filter[ $hook ]->callbacks[ $priority ][ $key ] = array(
					'function'      => static function () use ( $original_fn, $source, $accepted_args, $keep_visible, $notice_like_only ) {
						ob_start();
						if ( $accepted_args > 0 ) {
							call_user_func_array( $original_fn, array_fill( 0, $accepted_args, null ) );
						} else {
							call_user_func( $original_fn );
						}
						$html = ob_get_clean();

						if ( '' === trim( (string) $html ) ) {
							return;
						}

						// On shared hooks only intercept output that looks like an admin
						// notice; anything else is passed through untouched.
						if ( $notice_like_only && ! preg_match( '#class\s*=\s*["\'][^"\']*(?:notice|updated|update-nag|[\w-]+-nag)#i', $html ) ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Unmodified third-party output passed through untouched.
							echo $html;
							return;
						}

						// Decisions must ride on the notice ELEMENT, not an ancestor
						// wrapper: WordPress core (wp-admin/js/common.js) relocates bare
						// div.notice/updated/error out of any wrapper on load (e.g. into
						// .wrap / .tsoan-wrap), which would otherwise strip the marker.
						$mode = $keep_visible ? 'keep' : 'hide';
						list( $marked, $found ) = self::mark_notice_elements( $html, $mode, $source );

						if ( $found > 0 ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Third-party notice HTML with our data-* markers injected; CSS/JS act on the markers.
							echo $marked;
							return;
						}

						// Fallback for output with no recognisable notice element
						// (core will not relocate it, so an ancestor wrapper is safe).
						$wrap_class = $keep_visible ? 'tsoan-safe-group' : 'tsoan-hidden-group';
						$wrap_attrs = $keep_visible ? '' : ' aria-hidden="true"';
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw notice HTML; wrapper attrs are static/escaped.
						echo '<div class="' . $wrap_class . '" data-tsoan-source="' . esc_attr( $source ) . '"' . $wrap_attrs . '>' . $html . '</div>';
					},
					'accepted_args' => $accepted_args,
					'tsoan_wrapped' => true,
				);
			}
		}
	}

	/**
	 * Whether a source is one of the first-party TSO plugins / mu-plugins.
	 *
	 * Their notices (onboarding, setup, results) are intentional and must stay
	 * visible instead of being hidden by this plugin.
	 *
	 * @param string $source Plugin slug.
	 * @return bool
	 */
	private function is_own_family( $source ) {
		if ( '' === $source || 'unknown' === $source ) {
			return false;
		}
		return (
			0 === strpos( $source, 'tso-' ) ||
			0 === strpos( $source, 'tso_' ) ||
			0 === strpos( $source, 'mu-tso-' ) ||
			0 === strpos( $source, 'mu-tso_' )
		);
	}

	/**
	 * Tag notice elements in a captured HTML string with our data-* markers.
	 *
	 * WordPress core (wp-admin/js/common.js) relocates bare
	 * div.notice/div.updated/div.error out of any wrapper on load (moving them
	 * after the page header, e.g. into .wrap / .tsoan-wrap). Marking the element
	 * itself — instead of an ancestor wrapper — ensures the JS/CSS layers keep
	 * hiding or showing the right notices after they are moved.
	 *
	 * @param string $html   Captured notice HTML.
	 * @param string $mode   'keep' (stay visible) or 'hide'.
	 * @param string $source Plugin slug.
	 * @return array{0:string,1:int} Marked HTML and number of notice elements tagged.
	 */
	private static function mark_notice_elements( $html, $mode, $source ) {
		$found = 0;
		$attr  = ( 'keep' === $mode ? ' data-tsoan-keep="1"' : ' data-tsoan-hide="1"' )
			. ' data-tsoan-source="' . esc_attr( $source ) . '"';

		$html = (string) preg_replace_callback(
			'/<div\b[^>]*>/i',
			static function ( $matches ) use ( $attr, &$found ) {
				$tag = $matches[0];
				if ( false !== stripos( $tag, 'data-tsoan-keep' ) || false !== stripos( $tag, 'data-tsoan-hide' ) ) {
					return $tag;
				}
				if ( preg_match( '/class\s*=\s*["\'][^"\']*(?:notice|updated|error|[\w-]+-nag)/i', $tag ) ) {
					++$found;
					return substr( $tag, 0, -1 ) . $attr . '>';
				}
				return $tag;
			},
			$html
		);

		return array( $html, $found );
	}

	/**
	 * Stable callable key for dedup.
	 *
	 * @param mixed $callback Callable.
	 * @return string
	 */
	private function get_fn_key( $callback ) {
		if ( is_string( $callback ) ) {
			return 'fn:' . $callback;
		}

		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return 'method:' . $class . '::' . $callback[1];
		}

		if ( $callback instanceof Closure ) {
			try {
				$ref = new ReflectionFunction( $callback );
				return 'closure:' . $ref->getFileName() . ':' . $ref->getStartLine();
			} catch ( ReflectionException $e ) {
				return 'closure:' . spl_object_hash( $callback );
			}
		}

		return '';
	}

	/**
	 * Whether callback is WP core.
	 *
	 * @param array<string,mixed> $cb Callback entry.
	 * @return bool
	 */
	private function is_core_callback( array $cb ) {
		$file = $this->get_callback_file( $cb );
		if ( '' === $file ) {
			return false;
		}

		$file      = wp_normalize_path( $file );
		$admin_dir = wp_normalize_path( ABSPATH . 'wp-admin/' );
		$inc_dir   = wp_normalize_path( ABSPATH . 'wp-includes/' );

		return ( 0 === strpos( $file, $admin_dir ) ) || ( 0 === strpos( $file, $inc_dir ) );
	}

	/**
	 * File path for a callback.
	 *
	 * @param array<string,mixed> $cb Callback entry.
	 * @return string
	 */
	private function get_callback_file( array $cb ) {
		$fn = $cb['function'];

		try {
			if ( is_array( $fn ) && 2 === count( $fn ) ) {
				$ref = new ReflectionMethod( $fn[0], $fn[1] );
			} elseif ( is_string( $fn ) && function_exists( $fn ) ) {
				$ref = new ReflectionFunction( $fn );
			} elseif ( $fn instanceof Closure ) {
				$ref = new ReflectionFunction( $fn );
			} else {
				return '';
			}

			$path = $ref->getFileName();
			return $path ? $path : '';
		} catch ( ReflectionException $e ) {
			return '';
		}
	}

	/**
	 * Plugin slug for a callback.
	 *
	 * @param array<string,mixed> $cb Callback entry.
	 * @return string
	 */
	private function get_callback_source( array $cb ) {
		$file = $this->get_callback_file( $cb );
		if ( '' === $file ) {
			return 'unknown';
		}

		$file        = wp_normalize_path( $file );
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR . '/' );
		$themes_dir  = wp_normalize_path( get_theme_root() . '/' );

		if ( 0 === strpos( $file, $plugins_dir ) ) {
			$parts = explode( '/', substr( $file, strlen( $plugins_dir ) ) );
			return sanitize_key( $parts[0] );
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$mu_dir = wp_normalize_path( WPMU_PLUGIN_DIR . '/' );
			if ( 0 === strpos( $file, $mu_dir ) ) {
				$parts = explode( '/', substr( $file, strlen( $mu_dir ) ) );
				return 'mu-' . sanitize_key( $parts[0] );
			}
		}

		if ( 0 === strpos( $file, $themes_dir ) ) {
			return 'theme';
		}

		return 'unknown';
	}

	/**
	 * Whether a source is whitelisted.
	 *
	 * @param string $source Plugin slug.
	 * @return bool
	 */
	private function is_whitelisted( $source ) {
		return in_array( $source, $this->get_whitelist(), true );
	}

	/**
	 * Preload CSS that hides notices before first paint (no flash).
	 *
	 * @return string
	 */
	private function get_preload_css() {
		return ''
			/* Element-level marker (survives core notice relocation) + legacy wrapper. */
			. '.tsoan-hidden-group,[data-tsoan-hide]{display:none!important}'
			/* Vendor / SDK notices (never core). */
			. '#wpbody-content .fs-notice,'
			. '#wpbody-content .rank-math-notice,'
			. '#wpbody-content .wp-helpers-notice,'
			. '#wpbody-content .backuply-backup-nag,'
			. '#wpbody-content .woocommerce-message,'
			. '#wpbody-content .woocommerce-error,'
			. '#wpbody-content .woocommerce-info{display:none!important}'
			. '.tsoan-ok{display:block!important}'
			/* Reveal when toggled. */
			. '#wpbody-content .tsoan-revealed,'
			. '#wpbody-content .tsoan-revealed .notice,'
			. '#wpbody-content .tsoan-revealed .updated,'
			. '#wpbody-content .tsoan-revealed .update-nag,'
			. '#wpbody-content .tsoan-revealed .error,'
			. '#wpbody-content .tsoan-revealed .fs-notice,'
			. '#wpbody-content .tsoan-revealed .rank-math-notice,'
			. '#wpbody-content .tsoan-revealed .wp-helpers-notice,'
			. '#wpbody-content .tsoan-revealed .backuply-backup-nag,'
			. '#wpbody-content .tsoan-revealed .woocommerce-message,'
			. '#wpbody-content .tsoan-revealed .woocommerce-error,'
			. '#wpbody-content .tsoan-revealed .woocommerce-info,'
			. '#wpbody-content .notice.tsoan-revealed,'
			. '#wpbody-content .updated.tsoan-revealed,'
			. '#wpbody-content .update-nag.tsoan-revealed,'
			. '#wpbody-content .error.tsoan-revealed,'
			. '#wpbody-content .fs-notice.tsoan-revealed,'
			. '#wpbody-content .rank-math-notice.tsoan-revealed,'
			. '#wpbody-content .wp-helpers-notice.tsoan-revealed,'
			. '#wpbody-content .backuply-backup-nag.tsoan-revealed,'
			. '#wpbody-content .woocommerce-message.tsoan-revealed,'
			. '#wpbody-content .woocommerce-error.tsoan-revealed,'
			. '#wpbody-content .woocommerce-info.tsoan-revealed{display:block!important}'
			/* Reveal element-level marked notices when toggled (any location). */
			. '[data-tsoan-hide].tsoan-revealed{display:block!important}';
	}

	/**
	 * Enqueue admin assets + zero-flash inline CSS.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( $this->is_enabled() ) {
			wp_register_style( 'tsoan-preload', false, array(), TSOAN_VERSION );
			wp_enqueue_style( 'tsoan-preload' );
			wp_add_inline_style( 'tsoan-preload', $this->get_preload_css() );
		}

		wp_enqueue_style(
			'tsoan-admin',
			TSOAN_URL . 'assets/tsoan-admin.css',
			array(),
			TSOAN_VERSION
		);

		wp_enqueue_script(
			'tsoan-admin',
			TSOAN_URL . 'assets/tsoan-admin.js',
			array(),
			TSOAN_VERSION,
			true
		);

		wp_localize_script(
			'tsoan-admin',
			'tsoanAdminData',
			array(
				'enabled'      => $this->is_enabled() ? '1' : '0',
				'showAdminBar' => ! empty( $this->settings['show_admin_bar'] ) ? '1' : '0',
				'whitelist'    => array_values( $this->get_whitelist() ),
				'i18n'         => array(
					'hiddenNotices' => __( 'Hidden notices on this screen', 'tso-admin-notices' ),
					'showNotices'   => __( 'Show notices on this screen', 'tso-admin-notices' ),
					'hideNotices'   => __( 'Hide notices on this screen', 'tso-admin-notices' ),
					'noNotices'     => __( 'No notices hidden on this screen', 'tso-admin-notices' ),
				),
			)
		);
	}

	/**
	 * Admin bar toggle node.
	 *
	 * @return void
	 */
	public function add_admin_bar_node() {
		if ( empty( $this->settings['show_admin_bar'] ) || ! $this->is_enabled() ) {
			return;
		}

		global $wp_admin_bar;

		if ( ! ( $wp_admin_bar instanceof WP_Admin_Bar ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'tsoan-toggle',
				/* translators: Placeholder text shown in the admin bar while JS counts hidden notices. */
				'title' => '<span id="tsoan-ab-label">' . esc_html__( 'Notices: ...', 'tso-admin-notices' ) . '</span>',
				'href'  => '#',
				'meta'  => array(
					'class' => 'tsoan-admin-bar-node',
					'title' => esc_attr__( 'Show/hide plugin notices on this screen', 'tso-admin-notices' ),
				),
			)
		);
	}

	/**
	 * Settings page under Settings → Plugin Notices.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'TSO Admin Notices Manager', 'tso-admin-notices' ),
			__( 'Plugin Notices', 'tso-admin-notices' ),
			'manage_options',
			'tso-admin-notices',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register Settings API option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'tsoan_settings_group',
			TSOAN_OPTION,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::DEFAULTS,
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return self::DEFAULTS;
		}

		$raw_whitelist = is_array( $input['whitelist'] ?? null ) ? $input['whitelist'] : array();

		return array(
			'enabled'        => ! empty( $input['enabled'] ) ? 1 : 0,
			'show_admin_bar' => ! empty( $input['show_admin_bar'] ) ? 1 : 0,
			'whitelist'      => array_values( array_filter( array_map( 'sanitize_key', $raw_whitelist ) ) ),
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$whitelist   = $this->get_whitelist();
		?>
		<div class="wrap tsoan-wrap">
			<h1>
				<span class="tsoan-logo">&#x1F515;</span>
				<?php esc_html_e( 'TSO Admin Notices Manager', 'tso-admin-notices' ); ?>
			</h1>

			<p class="tsoan-description">
				<?php esc_html_e( 'Hides annoying plugin notices (promotional, backup, update messages, etc.) from the WordPress admin area. Notices are still generated but remain hidden; you can temporarily reveal them using the admin bar button.', 'tso-admin-notices' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'tsoan_settings_group' ); ?>

				<div class="tsoan-section">
					<h2><?php esc_html_e( 'General settings', 'tso-admin-notices' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable filtering', 'tso-admin-notices' ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( TSOAN_OPTION ); ?>[enabled]"
										value="1"
										<?php checked( 1, $this->settings['enabled'] ); ?> />
									<?php esc_html_e( 'Enable plugin notice filtering', 'tso-admin-notices' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Admin bar', 'tso-admin-notices' ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( TSOAN_OPTION ); ?>[show_admin_bar]"
										value="1"
										<?php checked( 1, $this->settings['show_admin_bar'] ); ?> />
									<?php esc_html_e( 'Show counter and toggle button in the admin bar', 'tso-admin-notices' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<div class="tsoan-section">
					<h2><?php esc_html_e( 'Plugin whitelist', 'tso-admin-notices' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Check the plugins whose notices you want to keep visible. Notices from checked plugins will not be hidden.', 'tso-admin-notices' ); ?>
					</p>

					<?php if ( empty( $all_plugins ) ) : ?>
						<p><?php esc_html_e( 'No installed plugins found.', 'tso-admin-notices' ); ?></p>
					<?php else : ?>
						<div class="tsoan-whitelist-container">
							<div class="tsoan-whitelist-search-wrap">
								<input type="search" id="tsoan-search"
									placeholder="<?php esc_attr_e( 'Filter plugins...', 'tso-admin-notices' ); ?>"
									autocomplete="off" />
							</div>
							<div class="tsoan-whitelist" id="tsoan-whitelist-list">
								<?php
								foreach ( $all_plugins as $plugin_file => $plugin_data ) :
									$dir     = dirname( $plugin_file );
									$slug    = sanitize_key( '.' === $dir ? basename( $plugin_file, '.php' ) : $dir );
									$name    = isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : $slug;
									$version = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';
									if ( TSOAN_PATH === plugin_dir_path( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
										continue;
									}
									?>
									<label class="tsoan-whitelist-item"
										data-name="<?php echo esc_attr( strtolower( $name ) ); ?>">
										<input type="checkbox"
											name="<?php echo esc_attr( TSOAN_OPTION ); ?>[whitelist][]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $whitelist, true ) ); ?> />
										<span class="tsoan-plugin-name"><?php echo esc_html( $name ); ?></span>
										<?php if ( '' !== $version ) : ?>
											<span class="tsoan-plugin-version">v<?php echo esc_html( $version ); ?></span>
										<?php endif; ?>
										<code class="tsoan-plugin-slug"><?php echo esc_html( $slug ); ?></code>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<?php submit_button( __( 'Save changes', 'tso-admin-notices' ) ); ?>
			</form>

			<div class="tsoan-footer">
				<strong>TSO Admin Notices Manager</strong> v<?php echo esc_html( TSOAN_VERSION ); ?> &mdash;
				<a href="https://tusoporteonline.es" target="_blank" rel="noopener noreferrer">Tu Soporte Online</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Activation: store defaults + migrate legacy option.
	 *
	 * @return void
	 */
	public static function activate() {
		$legacy = get_option( 'tso_an_settings', false );
		if ( false === get_option( TSOAN_OPTION, false ) ) {
			if ( false !== $legacy && is_array( $legacy ) ) {
				add_option( TSOAN_OPTION, wp_parse_args( $legacy, self::DEFAULTS ) );
				delete_option( 'tso_an_settings' );
			} else {
				add_option( TSOAN_OPTION, self::DEFAULTS );
			}
		}
	}

	/**
	 * Deactivation — keep settings.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Settings preserved on deactivation.
	}
}
