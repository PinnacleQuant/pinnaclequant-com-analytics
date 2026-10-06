<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQAnalytics_Admin {

	const SLUG_VISITORS = 'pqanalytics-visitors';
	const SLUG_CHAT     = 'pqanalytics-live-chat';
	const SLUG_SETTINGS = 'pqanalytics-settings';
	const SLUG_FLOW     = 'pqanalytics-flow';

	public static function tz_label() {
		return PQAnalytics_Stats::tz_label();
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_post_pqanalytics_export', array( __CLASS__, 'export_csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PQANALYTICS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'When you visit this site, its own website software notes your IP address, approximate location (city and region), browser and device type, the page that referred you, and the pages you read during your visit. These details remain in the site\'s own database for a set number of days and are then deleted. They are not shared with anyone, and no advertising or analytics company receives them. One small cookie holding a random ID lets the site recognize a returning browser; it holds no personal details.', 'pinnaclequant-com-analytics' ) . '</p>';
		wp_add_privacy_policy_content( 'PinnacleQuant.com Analytics', $text );
	}

	public static function chat_addon_active() {
		return defined( 'PQC_VERSION' );
	}

	public static function view_cap() {
		return (int) PQAnalytics_Settings::get( 'editors_view' ) ? 'edit_others_posts' : 'manage_options';
	}

	public static function menu() {
		$view = self::view_cap();
		add_menu_page(
			__( 'PinnacleQuant.com Analytics', 'pinnaclequant-com-analytics' ),
			__( 'PinnacleQuant.com Analytics', 'pinnaclequant-com-analytics' ),
			$view,
			self::SLUG_VISITORS,
			array( __CLASS__, 'render_visitors' ),
			'dashicons-visibility',
			26
		);
		add_submenu_page(
			self::SLUG_VISITORS,
			__( 'Visitors', 'pinnaclequant-com-analytics' ),
			__( 'Visitors', 'pinnaclequant-com-analytics' ),
			$view,
			self::SLUG_VISITORS,
			array( __CLASS__, 'render_visitors' )
		);
		add_submenu_page(
			self::SLUG_VISITORS,
			__( 'Traffic flow', 'pinnaclequant-com-analytics' ),
			__( 'Traffic flow', 'pinnaclequant-com-analytics' ),
			$view,
			self::SLUG_FLOW,
			array( __CLASS__, 'render_flow' )
		);
		if ( ! self::chat_addon_active() ) {
			add_submenu_page(
				self::SLUG_VISITORS,
				__( 'Live Chat', 'pinnaclequant-com-analytics' ),
				__( 'Live Chat', 'pinnaclequant-com-analytics' ),
				'manage_options',
				self::SLUG_CHAT,
				array( __CLASS__, 'render_chat_promo' )
			);
		}
		add_submenu_page(
			self::SLUG_VISITORS,
			__( 'Settings', 'pinnaclequant-com-analytics' ),
			__( 'Settings', 'pinnaclequant-com-analytics' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG_SETTINGS );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'pinnaclequant-com-analytics' ) . '</a>' );
		return $links;
	}

	public static function register_settings() {
		register_setting(
			'pqanalytics_settings_group',
			PQAnalytics_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'PQAnalytics_Settings', 'sanitize' ),
				'default'           => PQAnalytics_Settings::defaults(),
			)
		);
		if ( isset( $_GET['pqanalytics_dismiss_setup'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'pqanalytics_dismiss_setup' ) ) {
			update_option( 'pqanalytics_show_setup', 0 );
			wp_safe_redirect( remove_query_arg( array( 'pqanalytics_dismiss_setup', '_wpnonce' ) ) );
			exit;
		}
	}

	private static function is_plugin_screen( $hook ) {
		$screens = array(
			'toplevel_page_' . self::SLUG_VISITORS,
			'pinnaclequant-com-analytics_page_' . self::SLUG_SETTINGS,
			'pinnaclequant-com-analytics_page_' . self::SLUG_CHAT,
			'pinnaclequant-com-analytics_page_' . self::SLUG_FLOW,
		);
		return in_array( $hook, $screens, true );
	}

	public static function assets( $hook ) {
		$ours = self::is_plugin_screen( $hook );
		if ( ! $ours && ( 'index.php' !== $hook || ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		wp_enqueue_style( 'pqanalytics-admin', PQANALYTICS_URL . 'assets/admin.css', array(), PQANALYTICS_VERSION );
		if ( ! $ours ) {
			return;
		}
		$screen = 'other';
		if ( 'toplevel_page_' . self::SLUG_VISITORS === $hook ) {
			$screen = 'visitors';
		} elseif ( 'pinnaclequant-com-analytics_page_' . self::SLUG_SETTINGS === $hook ) {
			$screen = 'settings';
		} elseif ( 'pinnaclequant-com-analytics_page_' . self::SLUG_FLOW === $hook ) {
			$screen = 'flow';
		}
		wp_enqueue_script( 'pqanalytics-admin', PQANALYTICS_URL . 'assets/admin.js', array(), PQANALYTICS_VERSION, true );
		wp_localize_script(
			'pqanalytics-admin',
			'PQANALYTICS_ADMIN',
			array(
				'root'        => esc_url_raw( rest_url( 'pqanalytics/v1/admin/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'screen'      => $screen,
				'refresh'     => 15000,
				'liveSeconds' => PQANALYTICS_LIVE_SECONDS,
				'chatUrl'     => esc_url_raw( apply_filters( 'pqanalytics_row_chat_url', admin_url( 'admin.php?page=' . self::SLUG_CHAT ) ) ),
				'chatByVid'   => (bool) apply_filters( 'pqanalytics_row_chat_by_vid', false ),
				'canBlock'    => current_user_can( 'manage_options' ),
				'i18n'        => array(
					'live'         => __( 'Live', 'pinnaclequant-com-analytics' ),
					'new'          => __( 'New', 'pinnaclequant-com-analytics' ),
					'returning'    => __( 'Returning', 'pinnaclequant-com-analytics' ),
					'member'       => __( 'Member', 'pinnaclequant-com-analytics' ),
					'visit'        => __( 'visit', 'pinnaclequant-com-analytics' ),
					'firstSeen'    => __( 'first seen', 'pinnaclequant-com-analytics' ),
					'block'        => __( 'Block from site', 'pinnaclequant-com-analytics' ),
					'blocked'      => __( 'Blocked', 'pinnaclequant-com-analytics' ),
					'unblock'      => __( 'Unblock', 'pinnaclequant-com-analytics' ),
					'path'         => __( 'Pages', 'pinnaclequant-com-analytics' ),
					'chat'         => __( 'Chat', 'pinnaclequant-com-analytics' ),
					'note'         => __( 'Note:', 'pinnaclequant-com-analytics' ),
					'noteSaved'    => __( 'Saved', 'pinnaclequant-com-analytics' ),
					'noRows'       => __( 'No visits yet. Open your site in a private window, or from your phone on cellular data, and the row appears within seconds.', 'pinnaclequant-com-analytics' ),
					'noRowsF'      => __( 'Nothing matches this filter.', 'pinnaclequant-com-analytics' ),
					'confirm'      => __( 'Block this address from the whole site? Every request from it gets a 403 until you unblock it. Addresses are often shared by many people and change over time.', 'pinnaclequant-com-analytics' ),
					'error'        => __( 'The request failed. Reload the page and try again.', 'pinnaclequant-com-analytics' ),
					'updated'      => __( 'Updated', 'pinnaclequant-com-analytics' ),
					'pages'        => __( 'pages', 'pinnaclequant-com-analytics' ),
					'page'         => __( 'page', 'pinnaclequant-com-analytics' ),
					'seconds'      => __( 's', 'pinnaclequant-com-analytics' ),
					'download'     => __( 'Download', 'pinnaclequant-com-analytics' ),
					'update'       => __( 'Update', 'pinnaclequant-com-analytics' ),
					'downloading'  => __( 'Downloading', 'pinnaclequant-com-analytics' ),
					'extracting'   => __( 'Extracting and checking the file', 'pinnaclequant-com-analytics' ),
					'installed'    => __( 'Installed', 'pinnaclequant-com-analytics' ),
					'notInstalled' => __( 'Not installed', 'pinnaclequant-com-analytics' ),
					'retry'        => __( 'Retry', 'pinnaclequant-com-analytics' ),
					'copied'       => __( 'Copied', 'pinnaclequant-com-analytics' ),
					'keyword'      => __( 'keyword', 'pinnaclequant-com-analytics' ),
					'campaign'     => __( 'campaign', 'pinnaclequant-com-analytics' ),
					'noFlow'       => __( 'Nothing in this range yet. Page paths are collected from the moment this version was installed.', 'pinnaclequant-com-analytics' ),
					'allPages'     => __( 'From one page to the next', 'pinnaclequant-com-analytics' ),
					'nextFrom'     => __( 'After this page, visitors went to', 'pinnaclequant-com-analytics' ),
					'beforeTo'     => __( 'Before this page, visitors were on', 'pinnaclequant-com-analytics' ),
					'leftFrom'     => __( 'visits ended on', 'pinnaclequant-com-analytics' ),
					'viewAll'      => __( 'View all pages visited', 'pinnaclequant-com-analytics' ),
					'hideAll'      => __( 'Show fewer', 'pinnaclequant-com-analytics' ),
					'current'      => __( 'current page', 'pinnaclequant-com-analytics' ),
					'geoWorking'   => __( 'Downloading the location files. Keep this page open until it says Installed.', 'pinnaclequant-com-analytics' ),
					'geoDone'      => __( 'Location files installed. City and state fill in from the next visit.', 'pinnaclequant-com-analytics' ),
					'geoFailed'    => __( 'The download did not finish. Click the button to try again.', 'pinnaclequant-com-analytics' ),
					'geoWaiting'   => __( 'Another download is running', 'pinnaclequant-com-analytics' ),
					'visits'       => __( 'visits', 'pinnaclequant-com-analytics' ),
					'capped'       => __( 'Common paths are based on the most recent 60,000 page views in this range.', 'pinnaclequant-com-analytics' ),
					'minSec'       => __( 'm', 'pinnaclequant-com-analytics' ),
					/* translators: 1: bounce rate today, 2: bounce rate over the last 7 days */
					'bounceSub'    => __( 'today %1$s, 7 days %2$s', 'pinnaclequant-com-analytics' ),
					'sending'      => __( 'Sending', 'pinnaclequant-com-analytics' ),
					/* translators: %s: email address */
					'sampleSent'   => __( 'Sent to %s. If it is not in the inbox within a minute, look in the spam folder.', 'pinnaclequant-com-analytics' ),
				),
			)
		);
	}

	private static function notice_pending() {
		return (bool) get_option( 'pqanalytics_show_setup', 0 ) && current_user_can( 'manage_options' );
	}

	public static function setup_notice() {
		if ( ! self::notice_pending() ) {
			return;
		}
		$geo_ready    = PQAnalytics_Geo::has( 'city' );
		$settings_url = admin_url( 'admin.php?page=' . self::SLUG_SETTINGS );
		$visitors_url = admin_url( 'admin.php?page=' . self::SLUG_VISITORS );
		$chat_url     = admin_url( 'admin.php?page=' . self::SLUG_CHAT );
		$dismiss_url  = wp_nonce_url( add_query_arg( 'pqanalytics_dismiss_setup', '1' ), 'pqanalytics_dismiss_setup' );
		?>
		<div class="notice notice-info pqanalytics-notice">
			<p><strong><?php esc_html_e( 'PinnacleQuant.com Analytics is ready.', 'pinnaclequant-com-analytics' ); ?></strong>
				<?php esc_html_e( 'Your visitors appear on the', 'pinnaclequant-com-analytics' ); ?> <a href="<?php echo esc_url( $visitors_url ); ?>"><?php esc_html_e( 'Visitors', 'pinnaclequant-com-analytics' ); ?></a> <?php esc_html_e( 'page as they arrive.', 'pinnaclequant-com-analytics' ); ?>
				<?php if ( ! $geo_ready ) : ?>
					<?php esc_html_e( 'To see city, state and ISP next to each visitor, download the two free location files from db-ip.com on the', 'pinnaclequant-com-analytics' ); ?> <a href="<?php echo esc_url( $settings_url . '#pqanalytics-geo' ); ?>"><?php esc_html_e( 'Settings', 'pinnaclequant-com-analytics' ); ?></a> <?php esc_html_e( 'page.', 'pinnaclequant-com-analytics' ); ?>
				<?php endif; ?>
				<?php if ( ! self::chat_addon_active() ) : ?>
					<?php esc_html_e( 'You can also talk to your visitors in real time with the free live chat: see', 'pinnaclequant-com-analytics' ); ?> <a href="<?php echo esc_url( $chat_url ); ?>"><?php esc_html_e( 'Live Chat', 'pinnaclequant-com-analytics' ); ?></a>.
				<?php endif; ?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Dismiss', 'pinnaclequant-com-analytics' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function thank_you_box() {
		?>
		<div class="pqanalytics-thanks">
			<?php esc_html_e( 'Thank you for using PinnacleQuant.com Analytics. It is free, and if it serves you well, a', 'pinnaclequant-com-analytics' ); ?> <a href="<?php echo esc_url( PQANALYTICS_REVIEW_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'review', 'pinnaclequant-com-analytics' ); ?></a> <?php esc_html_e( 'on WordPress.org helps others find it. All the best, Raffi', 'pinnaclequant-com-analytics' ); ?>
		</div>
		<?php
	}

	public static function dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'pqanalytics_dashboard', __( 'PinnacleQuant.com Analytics', 'pinnaclequant-com-analytics' ), array( __CLASS__, 'render_widget' ) );
	}

	public static function render_widget() {
		$cards = PQAnalytics_Stats::cards();
		$url   = admin_url( 'admin.php?page=' . self::SLUG_VISITORS );
		?>
		<div class="pqanalytics-widget">
			<div class="pqanalytics-widget-row">
				<div><span class="pqanalytics-widget-num pqanalytics-live-num"><?php echo esc_html( number_format_i18n( $cards['live'] ) ); ?></span><span class="pqanalytics-widget-label"><?php esc_html_e( 'on the site now', 'pinnaclequant-com-analytics' ); ?></span></div>
				<div><span class="pqanalytics-widget-num"><?php echo esc_html( number_format_i18n( $cards['today']['u'] ) ); ?></span><span class="pqanalytics-widget-label"><?php esc_html_e( 'visitors today', 'pinnaclequant-com-analytics' ); ?></span></div>
				<div><span class="pqanalytics-widget-num"><?php echo esc_html( number_format_i18n( $cards['today']['p'] ) ); ?></span><span class="pqanalytics-widget-label"><?php esc_html_e( 'page views today', 'pinnaclequant-com-analytics' ); ?></span></div>
				<div><span class="pqanalytics-widget-num"><?php echo esc_html( number_format_i18n( $cards['d30']['u'] ) ); ?></span><span class="pqanalytics-widget-label"><?php esc_html_e( 'visitors, 30 days', 'pinnaclequant-com-analytics' ); ?></span></div>
			</div>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open Visitors', 'pinnaclequant-com-analytics' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_FLOW ) ); ?>"><?php esc_html_e( 'Traffic flow', 'pinnaclequant-com-analytics' ); ?></a>
				<?php if ( ! self::chat_addon_active() ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_CHAT ) ); ?>"><?php esc_html_e( 'Add free live chat', 'pinnaclequant-com-analytics' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	public static function render_visitors() {
		if ( ! current_user_can( self::view_cap() ) ) {
			return;
		}
		$tz         = self::tz_label();
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=pqanalytics_export&days=30' ), 'pqanalytics_export' );
		$geo_ready  = PQAnalytics_Geo::has( 'city' ) && PQAnalytics_Geo::has( 'asn' );
		?>
		<div class="wrap pqanalytics-wrap" id="pqanalytics-app">
			<h1><?php esc_html_e( 'Visitors', 'pinnaclequant-com-analytics' ); ?></h1>
			<p class="pqanalytics-sub">
				<?php esc_html_e( 'Times shown in', 'pinnaclequant-com-analytics' ); ?> <?php echo esc_html( $tz ); ?>. <?php esc_html_e( 'This page refreshes itself every 15 seconds.', 'pinnaclequant-com-analytics' ); ?>
				<span id="pqanalytics-updated"></span>
			</p>
			<?php if ( ! $geo_ready && current_user_can( 'manage_options' ) ) : ?>
			<div class="notice notice-info inline" id="pqanalytics-geo-notice"><p>
				<span id="pqanalytics-geo-text"><?php esc_html_e( 'City, state and ISP stay blank until two free location files from db-ip.com are downloaded to your uploads folder (about 140 MB, refreshed once a month). Your visitors\' details never leave your site.', 'pinnaclequant-com-analytics' ); ?></span>
				<button type="button" class="button button-primary" id="pqanalytics-geo-start"><?php esc_html_e( 'Download the location files', 'pinnaclequant-com-analytics' ); ?></button>
				<span id="pqanalytics-geo-auto" class="pqanalytics-muted"></span>
			</p></div>
			<?php endif; ?>

			<div class="pqanalytics-cards" id="pqanalytics-cards" aria-live="polite">
				<div class="pqanalytics-card pqanalytics-card-live"><div class="pqanalytics-num" data-card="live">–</div><div class="pqanalytics-label"><?php esc_html_e( 'On the site now', 'pinnaclequant-com-analytics' ); ?></div><div class="pqanalytics-sublabel"><?php esc_html_e( 'active in the last 60 seconds', 'pinnaclequant-com-analytics' ); ?></div></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-card="today">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Today', 'pinnaclequant-com-analytics' ); ?></div><div class="pqanalytics-sublabel" data-sub="today"></div><svg class="pqanalytics-spark" data-spark="today" aria-hidden="true"></svg></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-card="yesterday">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Yesterday', 'pinnaclequant-com-analytics' ); ?></div><div class="pqanalytics-sublabel" data-sub="yesterday"></div></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-card="d7">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Last 7 days', 'pinnaclequant-com-analytics' ); ?></div><div class="pqanalytics-sublabel" data-sub="d7"></div><svg class="pqanalytics-spark" data-spark="d7" aria-hidden="true"></svg></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-card="d30">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Last 30 days', 'pinnaclequant-com-analytics' ); ?></div><div class="pqanalytics-sublabel" data-sub="d30"></div><svg class="pqanalytics-spark" data-spark="d30" aria-hidden="true"></svg></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-card="bounce">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Bounce rate, 30 days', 'pinnaclequant-com-analytics' ); ?></div><div class="pqanalytics-sublabel" data-sub="bounce"></div><div class="pqanalytics-sublabel"><?php esc_html_e( 'one page, under 10 seconds', 'pinnaclequant-com-analytics' ); ?></div></div>
				<div class="pqanalytics-card pqanalytics-card-bots"><div class="pqanalytics-num" data-card="bots">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Bots today', 'pinnaclequant-com-analytics' ); ?></div><div class="pqanalytics-sublabel" data-sub="bots"></div><svg class="pqanalytics-spark" data-spark="bots" aria-hidden="true"></svg></div>
			</div>
			<p class="description pqanalytics-cards-note">
				<?php esc_html_e( 'Large numbers are unique visitors; small numbers are page views. Bots are counted from crawler requests that reach PHP; pages served from a cache or CDN are not seen.', 'pinnaclequant-com-analytics' ); ?>
				<?php if ( PQAnalytics_Settings::get( 'exclude_logged_in' ) ) : ?>
					<?php esc_html_e( 'Logged-in users are left out, so you do not count yourself while you are logged in to WordPress. To see yourself here, open your site in a private window or on your phone. You can change this under', 'pinnaclequant-com-analytics' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) ); ?>"><?php esc_html_e( 'Settings', 'pinnaclequant-com-analytics' ); ?></a>.
				<?php endif; ?>
			</p>

			<div class="pqanalytics-grid">
				<div class="pqanalytics-box">
					<h2><?php esc_html_e( 'Today by hour', 'pinnaclequant-com-analytics' ); ?></h2>
					<table class="widefat striped pqanalytics-table" id="pqanalytics-hourly"><thead><tr><th><?php esc_html_e( 'Hour', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Visitors', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Page views', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
				</div>
				<div class="pqanalytics-box">
					<h2><?php esc_html_e( 'Last 30 days', 'pinnaclequant-com-analytics' ); ?></h2>
					<table class="widefat striped pqanalytics-table" id="pqanalytics-daily"><thead><tr><th><?php esc_html_e( 'Day', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Visitors', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Page views', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Bots', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
				</div>
				<div class="pqanalytics-box">
					<h2><?php esc_html_e( 'Screens, last 30 days', 'pinnaclequant-com-analytics' ); ?></h2>
					<table class="widefat striped pqanalytics-table" id="pqanalytics-classes"><thead><tr><th><?php esc_html_e( 'Screen type', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Visitors', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Share', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
					<table class="widefat striped pqanalytics-table" id="pqanalytics-res"><thead><tr><th><?php esc_html_e( 'Resolution (physical pixels)', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Scaling', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Visitors', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Share', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
				</div>
			</div>

			<div class="pqanalytics-toolbar">
				<h2><?php esc_html_e( 'Visitors', 'pinnaclequant-com-analytics' ); ?> <span class="pqanalytics-count" id="pqanalytics-count"></span></h2>
				<div class="pqanalytics-filters" role="group">
					<button type="button" class="button pqanalytics-filter is-active" data-filter="all"><?php esc_html_e( 'All', 'pinnaclequant-com-analytics' ); ?></button>
					<button type="button" class="button pqanalytics-filter" data-filter="live"><?php esc_html_e( 'On the site now', 'pinnaclequant-com-analytics' ); ?></button>
					<button type="button" class="button pqanalytics-filter" data-filter="returning"><?php esc_html_e( 'Returning', 'pinnaclequant-com-analytics' ); ?></button>
					<button type="button" class="button pqanalytics-filter" data-filter="bots"><?php esc_html_e( 'Bots', 'pinnaclequant-com-analytics' ); ?></button>
				</div>
				<label class="screen-reader-text" for="pqanalytics-search"><?php esc_html_e( 'Search visitors', 'pinnaclequant-com-analytics' ); ?></label>
				<input type="search" id="pqanalytics-search" placeholder="<?php esc_attr_e( 'IP, city, state, ISP, source, page, name', 'pinnaclequant-com-analytics' ); ?>">
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV (30 days)', 'pinnaclequant-com-analytics' ); ?></a>
				<?php endif; ?>
			</div>

			<div class="pqanalytics-table-card">
			<table class="widefat pqanalytics-table pqanalytics-visitors" id="pqanalytics-visitors">
				<thead><tr>
					<th class="pqanalytics-col-live"></th>
					<th><?php esc_html_e( 'Last seen', 'pinnaclequant-com-analytics' ); ?></th>
					<th><?php esc_html_e( 'IP address', 'pinnaclequant-com-analytics' ); ?></th>
					<th><?php esc_html_e( 'Location', 'pinnaclequant-com-analytics' ); ?></th>
					<th><?php esc_html_e( 'Visitor', 'pinnaclequant-com-analytics' ); ?></th>
					<th><?php esc_html_e( 'Came from', 'pinnaclequant-com-analytics' ); ?></th>
					<th><?php esc_html_e( 'Pages this visit', 'pinnaclequant-com-analytics' ); ?></th>
					<th><?php esc_html_e( 'Screen', 'pinnaclequant-com-analytics' ); ?></th>
					<th><?php esc_html_e( 'Browser', 'pinnaclequant-com-analytics' ); ?></th>
					<th><?php esc_html_e( 'ISP', 'pinnaclequant-com-analytics' ); ?></th>
				</tr></thead>
				<tbody><tr><td colspan="10"><?php esc_html_e( 'Loading', 'pinnaclequant-com-analytics' ); ?></td></tr></tbody>
			</table>
			</div>
			<div class="pqanalytics-pager" id="pqanalytics-pager"></div>
			<p class="pqanalytics-credit"><?php esc_html_e( 'IP geolocation by', 'pinnaclequant-com-analytics' ); ?> <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>.</p>
			<?php self::thank_you_box(); ?>
		</div>
		<?php
	}

	public static function render_chat_promo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$site    = home_url( '/' );
		$subject = 'Free chat key request - ' . wp_parse_url( $site, PHP_URL_HOST );
		$body    = "Full name: \nWebsite: " . $site . "\nWordPress: " . get_bloginfo( 'version' ) . "\nPlugin: " . PQANALYTICS_VERSION;
		$mailto  = 'mailto:' . PQANALYTICS_CHAT_EMAIL . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body );
		?>
		<div class="wrap pqanalytics-wrap">
			<h1><?php esc_html_e( 'Chat with your visitors live', 'pinnaclequant-com-analytics' ); ?></h1>
			<div class="pqanalytics-promo">
				<div class="pqanalytics-promo-preview">
					<div class="pqanalytics-promo-site">
						<div class="pqanalytics-promo-line pqanalytics-promo-line-a"></div>
						<div class="pqanalytics-promo-line"></div>
						<div class="pqanalytics-promo-line pqanalytics-promo-line-b"></div>
						<div class="pqanalytics-promo-panel">
							<div class="pqanalytics-promo-panel-head"><span class="pqanalytics-promo-dot"></span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
							<div class="pqanalytics-promo-panel-body">
								<div class="pqanalytics-promo-msg pqanalytics-promo-msg-v"><?php esc_html_e( 'Is this still in stock?', 'pinnaclequant-com-analytics' ); ?></div>
								<div class="pqanalytics-promo-msg pqanalytics-promo-msg-o"><?php esc_html_e( 'Yes, and it ships today.', 'pinnaclequant-com-analytics' ); ?></div>
							</div>
							<div class="pqanalytics-promo-panel-foot">Powered by PinnacleQuant.com</div>
						</div>
						<div class="pqanalytics-promo-button"><span class="pqanalytics-promo-dot"></span><?php esc_html_e( 'Chat with', 'pinnaclequant-com-analytics' ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
						<div class="pqanalytics-promo-credit">Powered by PinnacleQuant.com</div>
					</div>
					<p class="description"><?php esc_html_e( 'Your company name on the button, your color, bottom right or bottom left.', 'pinnaclequant-com-analytics' ); ?></p>
				</div>
				<div class="pqanalytics-promo-text">
					<p><?php esc_html_e( 'See who is on your site right now and start a conversation.', 'pinnaclequant-com-analytics' ); ?></p>
					<p><?php esc_html_e( 'Talk to visitors in real time from this admin, on your computer or your phone.', 'pinnaclequant-com-analytics' ); ?></p>
					<p><?php esc_html_e( 'When you are not available to chat, the widget takes their name, email, phone and a brief message and emails it to you.', 'pinnaclequant-com-analytics' ); ?></p>
					<p><strong><?php esc_html_e( 'Free. No account, no card.', 'pinnaclequant-com-analytics' ); ?></strong></p>
					<hr>
					<div class="pqanalytics-step">
						<span class="pqanalytics-step-num">1</span>
						<div>
							<strong><?php esc_html_e( 'Download the chat add-on', 'pinnaclequant-com-analytics' ); ?></strong>
							<p class="description"><?php esc_html_e( 'A small plugin that adds the Chat page to this menu. Install it under Plugins, Add New Plugin, Upload Plugin.', 'pinnaclequant-com-analytics' ); ?></p>
							<a class="button button-primary" href="<?php echo esc_url( PQANALYTICS_CHAT_PAGE_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Download from PinnacleQuant.com/analytics-systems', 'pinnaclequant-com-analytics' ); ?></a>
						</div>
					</div>
					<div class="pqanalytics-step">
						<span class="pqanalytics-step-num">2</span>
						<div>
							<strong><?php esc_html_e( 'Request your free key', 'pinnaclequant-com-analytics' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Email', 'pinnaclequant-com-analytics' ); ?> <?php echo esc_html( PQANALYTICS_CHAT_EMAIL ); ?> <?php esc_html_e( 'with your full name and your website address. We email you the key; paste it in Chat Setup and the chat is on.', 'pinnaclequant-com-analytics' ); ?></p>
							<a class="button" href="<?php echo esc_url( $mailto ); ?>"><?php esc_html_e( 'Email my request', 'pinnaclequant-com-analytics' ); ?></a>
							<pre class="pqanalytics-mail-template"><?php echo esc_html( "To: " . PQANALYTICS_CHAT_EMAIL . "\nSubject: " . $subject . "\n" . $body ); ?></pre>
						</div>
					</div>
					<p class="description"><?php esc_html_e( 'Once the add-on is active, this page becomes your chat setup and, after that, your Chat page.', 'pinnaclequant-com-analytics' ); ?></p>
				</div>
			</div>
			<?php self::thank_you_box(); ?>
		</div>
		<?php
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = PQAnalytics_Settings::all();
		$status  = PQAnalytics_Geo::status();
		$blocked = PQAnalytics_Blocker::blocked();
		$my_ip   = PQAnalytics_IP::current();
		$hours   = array();
		$tz      = PQAnalytics_Stats::tz();
		$clock   = new DateTime( 'now', $tz );
		$tfmt    = get_option( 'time_format' ) ? get_option( 'time_format' ) : 'g:i a';
		for ( $h = 0; $h < 24; $h++ ) {
			$clock->setTime( $h, 0, 0 );
			$hours[ $h ] = wp_date( $tfmt, $clock->getTimestamp(), $tz );
		}
		$wp_tz = (string) get_option( 'timezone_string' );
		if ( '' === $wp_tz ) {
			$offset = (float) get_option( 'gmt_offset' );
			$wp_tz  = 'UTC' . ( $offset >= 0 ? '+' : '' ) . rtrim( rtrim( number_format( $offset, 2, '.', '' ), '0' ), '.' );
		}
		$tz_selected = '' !== $s['timezone'] ? $s['timezone'] : $wp_tz;
		$days = array();
		for ( $n = 1; $n <= 7; $n++ ) {
			$days[ $n ] = $GLOBALS['wp_locale']->get_weekday( $n % 7 );
		}
		$next_report = PQAnalytics_Report::next_label();
		?>
		<div class="wrap pqanalytics-wrap" id="pqanalytics-settings">
			<h1><?php esc_html_e( 'Settings', 'pinnaclequant-com-analytics' ); ?></h1>

			<div class="pqanalytics-box">
			<h2 id="pqanalytics-geo"><?php esc_html_e( 'Location lookup', 'pinnaclequant-com-analytics' ); ?></h2>
			<p><?php esc_html_e( 'City, state, country and ISP are looked up on this server from two free database files stored in your uploads folder. Nothing about your visitors leaves your site. The files come from db-ip.com and need no account. Nothing is downloaded until you click Download; after that, the plugin fetches a fresh copy from db-ip.com once a month.', 'pinnaclequant-com-analytics' ); ?></p>
			<table class="widefat pqanalytics-table pqanalytics-geo-table">
				<thead><tr><th><?php esc_html_e( 'Database', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Status', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Action', 'pinnaclequant-com-analytics' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $status as $row ) : ?>
					<tr data-kind="<?php echo esc_attr( $row['kind'] ); ?>">
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td class="pqanalytics-geo-status">
							<?php
							if ( $row['installed'] ) {
								$when = $row['updated'] ? wp_date( get_option( 'date_format' ) ? get_option( 'date_format' ) : 'M j, Y', $row['updated'] ) : '';
								echo esc_html__( 'Installed', 'pinnaclequant-com-analytics' ) . ' (' . esc_html( size_format( $row['size'] ) ) . ', ' . esc_html__( 'downloaded', 'pinnaclequant-com-analytics' ) . ' ' . esc_html( $when ) . ')';
							} else {
								esc_html_e( 'Not installed', 'pinnaclequant-com-analytics' );
							}
							if ( '' !== $row['error'] ) {
								echo ' <span class="pqanalytics-error">' . esc_html( $row['error'] ) . '</span>';
							}
							?>
						</td>
						<td>
							<button type="button" class="button pqanalytics-geo-download" data-kind="<?php echo esc_attr( $row['kind'] ); ?>">
								<?php echo $row['installed'] ? esc_html__( 'Update', 'pinnaclequant-com-analytics' ) : esc_html__( 'Download', 'pinnaclequant-com-analytics' ); ?>
							</button>
							<span class="pqanalytics-geo-progress"></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'The download runs in 10 MB steps, so it works on shared hosting; the city file is about 130 MB. Leave this tab open until the row says Installed. If you close it early, the download finishes in the background.', 'pinnaclequant-com-analytics' ); ?></p>

			</div>
			<div class="pqanalytics-box">
			<form method="post" action="options.php">
				<?php settings_fields( 'pqanalytics_settings_group' ); ?>
				<h2><?php esc_html_e( 'Visitor details', 'pinnaclequant-com-analytics' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pqanalytics-timezone"><?php esc_html_e( 'Time zone', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td>
							<select id="pqanalytics-timezone" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[timezone]"><?php echo wp_kses( wp_timezone_choice( $tz_selected, get_user_locale() ), array( 'optgroup' => array( 'label' => true ), 'option' => array( 'value' => true, 'selected' => true ) ) ); ?></select>
							<p class="description"><?php esc_html_e( 'Every time shown in the plugin, the day totals and the email report follow this. It starts out as your WordPress time zone; pick your own if the WordPress clock is not on your local time.', 'pinnaclequant-com-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqanalytics-ip-source"><?php esc_html_e( 'Visitor IP address source', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td>
							<select id="pqanalytics-ip-source" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[ip_source]">
								<option value="auto" <?php selected( $s['ip_source'], 'auto' ); ?>><?php esc_html_e( 'Automatic (recommended)', 'pinnaclequant-com-analytics' ); ?></option>
								<option value="remote" <?php selected( $s['ip_source'], 'remote' ); ?>><?php esc_html_e( 'REMOTE_ADDR only (no proxy in front of the site)', 'pinnaclequant-com-analytics' ); ?></option>
								<option value="cf" <?php selected( $s['ip_source'], 'cf' ); ?>><?php esc_html_e( 'CF-Connecting-IP (Cloudflare)', 'pinnaclequant-com-analytics' ); ?></option>
								<option value="xff" <?php selected( $s['ip_source'], 'xff' ); ?>><?php esc_html_e( 'X-Forwarded-For (load balancer / reverse proxy)', 'pinnaclequant-com-analytics' ); ?></option>
								<option value="xreal" <?php selected( $s['ip_source'], 'xreal' ); ?>><?php esc_html_e( 'X-Real-IP (Nginx proxy)', 'pinnaclequant-com-analytics' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'With the current setting, your own request arrives as', 'pinnaclequant-com-analytics' ); ?> <code><?php echo esc_html( '' !== $my_ip ? $my_ip : '?' ); ?></code>. <?php esc_html_e( 'If that is not your public IP address, pick the header your host or CDN uses.', 'pinnaclequant-com-analytics' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logged-in users', 'pinnaclequant-com-analytics' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[exclude_logged_in]" value="1" <?php checked( $s['exclude_logged_in'] ); ?>> <?php esc_html_e( 'Leave out logged-in users, so you and your staff do not appear in the Visitors list', 'pinnaclequant-com-analytics' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who can see it', 'pinnaclequant-com-analytics' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[editors_view]" value="1" <?php checked( $s['editors_view'] ); ?>> <?php esc_html_e( 'Let Editors see the Visitors and Traffic flow pages too (Administrators always can; this Settings page stays with Administrators)', 'pinnaclequant-com-analytics' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pqanalytics-own-ips"><?php esc_html_e( 'Leave out these IP addresses', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td>
							<textarea id="pqanalytics-own-ips" class="large-text code" rows="2" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[own_ips]"><?php echo esc_textarea( $s['own_ips'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Comma-separated. Your office, your home, your uptime monitor.', 'pinnaclequant-com-analytics' ); ?>
							<button type="button" class="button button-small" id="pqanalytics-add-my-ip" data-ip="<?php echo esc_attr( $my_ip ); ?>"><?php esc_html_e( 'Add my current IP', 'pinnaclequant-com-analytics' ); ?></button></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqanalytics-retention"><?php esc_html_e( 'Keep visit details for', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td><input type="number" id="pqanalytics-retention" min="1" max="3650" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>"> <?php esc_html_e( 'days. Older visits are removed once a day.', 'pinnaclequant-com-analytics' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="pqanalytics-beat"><?php esc_html_e( 'Hosting resources', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td><?php esc_html_e( 'An open page reports every', 'pinnaclequant-com-analytics' ); ?> <input type="number" id="pqanalytics-beat" min="10" max="120" class="small-text" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[beat_seconds]" value="<?php echo esc_attr( $s['beat_seconds'] ); ?>"> <?php esc_html_e( 'seconds (10 to 120).', 'pinnaclequant-com-analytics' ); ?> <p class="description"><?php esc_html_e( 'A higher number uses fewer hosting resources; the time on each page is measured in steps of this size. Pages that are not on screen do not report.', 'pinnaclequant-com-analytics' ); ?></p></td>
					</tr>
				</table>
				<h2 id="pqanalytics-report-head"><?php esc_html_e( 'Email report', 'pinnaclequant-com-analytics' ); ?></h2>
				<p><?php esc_html_e( 'A short email with your visitors, page views, bounce rate, where they came from, the most read pages, locations and screens. Every day covers yesterday; every week covers the last 7 days.', 'pinnaclequant-com-analytics' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pqanalytics-report"><?php esc_html_e( 'Send the report', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td>
							<select id="pqanalytics-report" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[report]">
								<option value="off" <?php selected( $s['report'], 'off' ); ?>><?php esc_html_e( 'Off', 'pinnaclequant-com-analytics' ); ?></option>
								<option value="daily" <?php selected( $s['report'], 'daily' ); ?>><?php esc_html_e( 'Every day', 'pinnaclequant-com-analytics' ); ?></option>
								<option value="weekly" <?php selected( $s['report'], 'weekly' ); ?>><?php esc_html_e( 'Every week', 'pinnaclequant-com-analytics' ); ?></option>
							</select>
							<?php if ( '' !== $next_report ) : ?>
								<p class="description"><?php esc_html_e( 'Next report:', 'pinnaclequant-com-analytics' ); ?> <?php echo esc_html( $next_report ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqanalytics-report-hour"><?php esc_html_e( 'At what time', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td>
							<select id="pqanalytics-report-hour" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[report_hour]">
								<?php foreach ( $hours as $h => $label ) : ?>
									<option value="<?php echo esc_attr( $h ); ?>" <?php selected( (int) $s['report_hour'], $h ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php echo esc_html( self::tz_label() ); ?>
							<p class="description"><?php esc_html_e( 'One email, once a day or once a week, at or shortly after this time.', 'pinnaclequant-com-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqanalytics-report-day"><?php esc_html_e( 'Day for the weekly report', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td>
							<select id="pqanalytics-report-day" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[report_day]">
								<?php foreach ( $days as $n => $label ) : ?>
									<option value="<?php echo esc_attr( $n ); ?>" <?php selected( (int) $s['report_day'], $n ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqanalytics-report-to"><?php esc_html_e( 'Send to', 'pinnaclequant-com-analytics' ); ?></label></th>
						<td>
							<input type="text" id="pqanalytics-report-to" class="regular-text" name="<?php echo esc_attr( PQAnalytics_Settings::OPTION ); ?>[report_to]" value="<?php echo esc_attr( '' !== $s['report_to'] ? $s['report_to'] : get_option( 'admin_email' ) ); ?>">
							<p class="description"><?php esc_html_e( 'More than one address: separate them with commas.', 'pinnaclequant-com-analytics' ); ?></p>
							<p>
								<button type="button" class="button" id="pqanalytics-report-sample"><?php esc_html_e( 'Send me a sample now', 'pinnaclequant-com-analytics' ); ?></button>
								<span id="pqanalytics-report-msg" class="pqanalytics-muted"></span>
							</p>
						</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'The plugin sets one small cookie in the visitor\'s browser: a random ID so it can tell a returning visitor from a new one. It holds no personal details and is never shared. If your site shows a cookie notice, list it there. Suggested wording for your privacy policy is under Settings, Privacy, Policy Guide.', 'pinnaclequant-com-analytics' ); ?></p>
				<?php submit_button( __( 'Save settings', 'pinnaclequant-com-analytics' ) ); ?>
			</form>
			</div>

			<div class="pqanalytics-box">
			<h2 id="pqanalytics-blocked"><?php esc_html_e( 'Blocked from the site', 'pinnaclequant-com-analytics' ); ?></h2>
			<p><?php esc_html_e( 'Blocked addresses get a 403 for every request that reaches WordPress: pages, forms, logins, comments. A page already stored in a CDN or page cache is still served from the cache; for that, block the address in Cloudflare or your host\'s firewall as well. Administrators are never blocked. An address is often shared by many people (offices, mobile carriers, VPNs) and changes over time, so use this for clear abuse.', 'pinnaclequant-com-analytics' ); ?></p>
			<p>
				<label class="screen-reader-text" for="pqanalytics-block-ip"><?php esc_html_e( 'IP address to block', 'pinnaclequant-com-analytics' ); ?></label>
				<input type="text" id="pqanalytics-block-ip" class="regular-text code" placeholder="203.0.113.7">
				<button type="button" class="button" id="pqanalytics-block-add"><?php esc_html_e( 'Block this address', 'pinnaclequant-com-analytics' ); ?></button>
				<span id="pqanalytics-block-msg"></span>
			</p>
			<table class="widefat striped pqanalytics-table" id="pqanalytics-blocked-table">
				<thead><tr><th><?php esc_html_e( 'IP address', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Note', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Action', 'pinnaclequant-com-analytics' ); ?></th></tr></thead>
				<tbody>
				<?php if ( empty( $blocked ) ) : ?>
					<tr class="pqanalytics-empty"><td colspan="3"><?php esc_html_e( 'No addresses are blocked.', 'pinnaclequant-com-analytics' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $blocked as $ip => $note ) : ?>
					<tr data-ip="<?php echo esc_attr( $ip ); ?>"><td><code><?php echo esc_html( $ip ); ?></code></td><td><?php echo esc_html( $note ); ?></td><td><button type="button" class="button button-small pqanalytics-unblock" data-ip="<?php echo esc_attr( $ip ); ?>"><?php esc_html_e( 'Unblock', 'pinnaclequant-com-analytics' ); ?></button></td></tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</div>
			<p class="pqanalytics-credit"><?php esc_html_e( 'IP geolocation by', 'pinnaclequant-com-analytics' ); ?> <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>.</p>
			<?php self::thank_you_box(); ?>
		</div>
		<?php
	}

	private static function csv_row( $handle, $fields ) {
		fputcsv( $handle, $fields, ',', '"', '\\' );
	}

	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'pinnaclequant-com-analytics' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pqanalytics_export' );
		$days = isset( $_GET['days'] ) ? max( 1, min( 3650, (int) $_GET['days'] ) ) : 30;
		$rows = PQAnalytics_Stats::export_rows( $days );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pinnaclequant-analytics-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		self::csv_row( $out, array( 'started', 'last_seen', 'ip', 'visit_no', 'first_seen', 'member', 'name', 'note', 'email', 'phone', 'country', 'region', 'city', 'isp', 'source', 'source_type', 'referrer', 'keyword', 'utm_campaign', 'entry_url', 'last_url', 'pages', 'screen_w', 'screen_h', 'dpr', 'screen_class', 'browser', 'browser_ver', 'os', 'language', 'is_bot', 'bot_name', 'pages_path' ) );
		foreach ( $rows as $r ) {
			self::csv_row(
				$out,
				array(
					PQAnalytics_Stats::local( $r['started'], 'Y-m-d H:i:s' ),
					PQAnalytics_Stats::local( $r['last_beat'], 'Y-m-d H:i:s' ),
					$r['ip'],
					$r['visit_no'],
					isset( $r['first_seen'] ) ? PQAnalytics_Stats::local( $r['first_seen'], 'Y-m-d H:i:s' ) : '',
					isset( $r['member'] ) ? $r['member'] : '',
					isset( $r['name'] ) ? $r['name'] : '',
					isset( $r['label'] ) ? $r['label'] : '',
					isset( $r['email'] ) ? $r['email'] : '',
					isset( $r['phone'] ) ? $r['phone'] : '',
					$r['country'],
					$r['region'],
					$r['city'],
					$r['isp'],
					$r['source'],
					$r['source_type'],
					$r['referrer'],
					$r['keyword'],
					$r['utm_campaign'],
					$r['entry_url'],
					$r['last_url'],
					$r['pages'],
					$r['screen_w'],
					$r['screen_h'],
					$r['dpr'],
					$r['screen_class'],
					$r['browser'],
					$r['browser_ver'],
					$r['os'],
					$r['language'],
					$r['is_bot'],
					$r['bot_name'],
					isset( $r['pages_path'] ) ? (string) $r['pages_path'] : '',
				)
			);
		}
		exit;
	}

	public static function render_flow() {
		if ( ! current_user_can( self::view_cap() ) ) {
			return;
		}
		$ranges = array(
			'today'     => __( 'Today', 'pinnaclequant-com-analytics' ),
			'yesterday' => __( 'Yesterday', 'pinnaclequant-com-analytics' ),
			'7'         => __( 'Last 7 days', 'pinnaclequant-com-analytics' ),
			'30'        => __( 'Last 30 days', 'pinnaclequant-com-analytics' ),
			'90'        => __( 'Last 90 days', 'pinnaclequant-com-analytics' ),
		);
		?>
		<div class="wrap pqanalytics-wrap" id="pqanalytics-flow">
			<h1><?php esc_html_e( 'Traffic flow', 'pinnaclequant-com-analytics' ); ?></h1>
			<p class="pqanalytics-sub"><?php esc_html_e( 'Which pages visitors arrive on, where they go next, where they leave, and the paths they take most often. Bots are left out.', 'pinnaclequant-com-analytics' ); ?></p>

			<div class="pqanalytics-toolbar">
				<div class="pqanalytics-filters" role="group">
					<?php foreach ( $ranges as $key => $label ) : ?>
						<button type="button" class="button pqanalytics-range<?php echo '7' === (string) $key ? ' is-active' : ''; ?>" data-range="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
				<span id="pqanalytics-flow-status" class="pqanalytics-muted"></span>
			</div>

			<div class="pqanalytics-cards pqanalytics-cards-5" aria-live="polite">
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-flow="visits">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Visits', 'pinnaclequant-com-analytics' ); ?></div></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-flow="views">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Page views', 'pinnaclequant-com-analytics' ); ?></div></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-flow="per">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Pages per visit', 'pinnaclequant-com-analytics' ); ?></div></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-flow="single">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Bounce rate', 'pinnaclequant-com-analytics' ); ?></div><div class="pqanalytics-sublabel"><?php esc_html_e( 'one page, under 10 seconds', 'pinnaclequant-com-analytics' ); ?></div></div>
				<div class="pqanalytics-card"><div class="pqanalytics-num" data-flow="seconds">–</div><div class="pqanalytics-label"><?php esc_html_e( 'Average visit', 'pinnaclequant-com-analytics' ); ?></div></div>
			</div>

			<div class="pqanalytics-grid pqanalytics-grid-2">
				<div class="pqanalytics-box">
					<h2><?php esc_html_e( 'Entry pages', 'pinnaclequant-com-analytics' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The first page of each visit.', 'pinnaclequant-com-analytics' ); ?></p>
					<table class="widefat striped pqanalytics-table" id="pqanalytics-entries"><thead><tr><th><?php esc_html_e( 'Page', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Visits', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Share', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
				</div>
				<div class="pqanalytics-box">
					<h2><?php esc_html_e( 'Exit pages', 'pinnaclequant-com-analytics' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The last page of each visit.', 'pinnaclequant-com-analytics' ); ?></p>
					<table class="widefat striped pqanalytics-table" id="pqanalytics-exits"><thead><tr><th><?php esc_html_e( 'Page', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Visits', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Share', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
				</div>
			</div>

			<div class="pqanalytics-box pqanalytics-box-wide">
				<h2><?php esc_html_e( 'Where visitors go next', 'pinnaclequant-com-analytics' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Every step a visitor takes from one page to the next is counted. Pick a page to see what came right after it and right before it.', 'pinnaclequant-com-analytics' ); ?></p>
				<p>
					<label for="pqanalytics-from"><?php esc_html_e( 'Pick a page:', 'pinnaclequant-com-analytics' ); ?></label>
					<select id="pqanalytics-from"><option value=""><?php esc_html_e( 'All pages', 'pinnaclequant-com-analytics' ); ?></option></select>
				</p>
				<div class="pqanalytics-grid pqanalytics-grid-2">
					<div>
						<h3 id="pqanalytics-next-title"></h3>
						<table class="widefat striped pqanalytics-table" id="pqanalytics-next"><thead><tr><th id="pqanalytics-next-col1"><?php esc_html_e( 'From', 'pinnaclequant-com-analytics' ); ?></th><th id="pqanalytics-next-col2"><?php esc_html_e( 'To', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'How often', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Share', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
						<p class="pqanalytics-muted" id="pqanalytics-left"></p>
					</div>
					<div id="pqanalytics-before-box" hidden>
						<h3 id="pqanalytics-before-title"></h3>
						<table class="widefat striped pqanalytics-table" id="pqanalytics-before"><thead><tr><th><?php esc_html_e( 'Page', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'How often', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Share', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
					</div>
				</div>
			</div>

			<div class="pqanalytics-box pqanalytics-box-wide">
				<h2><?php esc_html_e( 'Common paths', 'pinnaclequant-com-analytics' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The first four pages of visits that read more than one page. Reloads of the same page are not counted as a move.', 'pinnaclequant-com-analytics' ); ?> <span id="pqanalytics-capped"></span></p>
				<table class="widefat striped pqanalytics-table" id="pqanalytics-common"><thead><tr><th><?php esc_html_e( 'Path', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Visits', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
			</div>

			<div class="pqanalytics-box pqanalytics-box-wide">
				<h2><?php esc_html_e( 'All pages', 'pinnaclequant-com-analytics' ); ?></h2>
				<table class="widefat striped pqanalytics-table" id="pqanalytics-pages"><thead><tr><th><?php esc_html_e( 'Page', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Views', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Visits', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Time on page', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Entries', 'pinnaclequant-com-analytics' ); ?></th><th><?php esc_html_e( 'Exits', 'pinnaclequant-com-analytics' ); ?></th></tr></thead><tbody></tbody></table>
				<p class="description"><?php esc_html_e( 'Time on page is the average for views that were followed by another action on the site; the last page of a visit is counted for the time the tab stayed open.', 'pinnaclequant-com-analytics' ); ?></p>
			</div>
			<?php self::thank_you_box(); ?>
		</div>
		<?php
	}
}
