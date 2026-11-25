<?php
/**
 * Connexion quotidienne au proxy (cron).
 *
 * @package Upsidly_Agent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron upsidly_agent_daily_connect.
 */
class Upsidly_Agent_Heartbeat {

	const HOOK = 'upsidly_agent_daily_connect';

	/**
	 * Branche les hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Programme le cron quotidien.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Déprogramme le cron.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Appel quotidien à /v1/connect, s'il y a une clé.
	 */
	public static function run() {
		if ( '' === Upsidly_Agent_Settings::get_key() ) {
			return;
		}
		Upsidly_Agent_Settings::connect_now();
	}
}
