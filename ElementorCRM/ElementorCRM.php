<?php
/**
 * Plugin Name:        ElementorCRM
 * Plugin URI:         https://WpGit.ir/landing/ElementorCRM
 * Description:        ذخیره فرم‌های المنتوری و ارسال آن در پیام‌رسان بله توسط وب‌هوک با قابلیت خروجی داده‌ها بصورت فایل csv .
 * Version:            1.0.0
 * Author:             im - JvD
 * Author URI:         https://mohamadjavadkarimi.ir/
 * Requires at least:  6.9
 * Requires PHP:       7.4
 * Requires Plugins:   elementor
 * Elementor requires at least: 3.20.0
 * Elementor tested up to: 4.1.1
 */

 
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ECRM_VERSION', '1.0.0' );
define( 'ECRM_PATH',    plugin_dir_path( __FILE__ ) );
define( 'ECRM_URL',     plugin_dir_url( __FILE__ ) );
define( 'ECRM_MIN_ELEMENTOR_VERSION', '3.20.0' );
define( 'ECRM_MIN_PHP_VERSION', '7.4' );


register_activation_hook( __FILE__, function() {
    require_once ECRM_PATH . 'includes/class-database.php';
    ECRM_Database::create_table();
} );

add_action( 'plugins_loaded', function() {

    if ( ! ecrm_is_compatible() ) {
        return;
    }

    require_once ECRM_PATH . 'includes/class-database.php';
    require_once ECRM_PATH . 'includes/class-bale-bot.php';
    require_once ECRM_PATH . 'includes/class-webhook.php';
    require_once ECRM_PATH . 'includes/class-admin.php';

    new ECRM_Admin();
    new ECRM_Webhook();

}, 20 );


add_action( 'plugins_loaded', function () {

    $updater_file = ECRM_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

    if ( file_exists( $updater_file ) ) {
        require_once $updater_file;
    }

    if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/im-JvD/ElementorCRM/',
            __FILE__,
            'elementorcrm'
        );

        $updateChecker->getVcsApi()->enableReleaseAssets();
    }

}, 5 );


function ecrm_admin_notice( $message, $type = 'error' ) {
    add_action( 'admin_notices', function () use ( $message, $type ) {
        printf(
            '<div class="notice notice-%1$s"><p>%2$s</p></div>',
            esc_attr( $type ),
            wp_kses_post( $message )
        );
    } );
}

function ecrm_is_compatible() {

    if ( version_compare( PHP_VERSION, ECRM_MIN_PHP_VERSION, '<' ) ) {
        ecrm_admin_notice(
            sprintf(
                'ElementorCRM نیاز به PHP %s یا بالاتر دارد. نسخه فعلی: %s',
                esc_html( ECRM_MIN_PHP_VERSION ),
                esc_html( PHP_VERSION )
            )
        );
        return false;
    }

    if ( ! did_action( 'elementor/loaded' ) ) {
        ecrm_admin_notice( 'ElementorCRM نیاز دارد افزونه Elementor نصب و فعال باشد.' );
        return false;
    }

    if ( ! defined( 'ELEMENTOR_VERSION' ) || version_compare( ELEMENTOR_VERSION, ECRM_MIN_ELEMENTOR_VERSION, '<' ) ) {
        ecrm_admin_notice(
            sprintf(
                'ElementorCRM نیاز به Elementor نسخه %s یا بالاتر دارد. نسخه فعلی: %s',
                esc_html( ECRM_MIN_ELEMENTOR_VERSION ),
                esc_html( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'نامشخص' )
            ),
            'warning'
        );
        return false;
    }

    if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
        ecrm_admin_notice( 'ElementorCRM نیاز دارد افزونه Elementor Pro نصب و فعال باشد.' );
        return false;
    }

    if ( version_compare( ELEMENTOR_PRO_VERSION, ECRM_MIN_ELEMENTOR_PRO_VERSION, '<' ) ) {
        ecrm_admin_notice(
            sprintf(
                'ElementorCRM نیاز به Elementor Pro نسخه %s یا بالاتر دارد. نسخه فعلی: %s',
                esc_html( ECRM_MIN_ELEMENTOR_PRO_VERSION ),
                esc_html( ELEMENTOR_PRO_VERSION )
            ),
            'warning'
        );
        return false;
    }

    return true;
}
