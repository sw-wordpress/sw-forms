<?php
/**
 * Versioned database schema.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Database;

final class Schema {

	public const VERSION_OPTION = 'swf_db_version';
	public const CURRENT        = 1;

	/** Per site tables: forms belong to a site, also on multisite. */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'swf_' . $name;
	}

	/**
	 * @return list<string> Short table names.
	 */
	public static function tables(): array {
		return array( 'forms', 'form_versions', 'entries', 'entry_values', 'entry_log' );
	}

	public static function needs_migration(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) < self::CURRENT;
	}

	/** Runs pending migrations. Safe to call repeatedly. */
	public static function migrate(): void {
		$installed = (int) get_option( self::VERSION_OPTION, 0 );
		if ( $installed >= self::CURRENT ) {
			return;
		}
		if ( $installed < 1 ) {
			self::migrate_to_1();
		}
		update_option( self::VERSION_OPTION, self::CURRENT, true );
	}

	private static function migrate_to_1(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'swf_';

		$statements = array(
			"CREATE TABLE {$p}forms (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  draft longtext NULL,
  current_version int(10) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;",
			"CREATE TABLE {$p}form_versions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  document longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY form_version (form_id,version)
) $charset;",
			"CREATE TABLE {$p}entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL,
  form_version int(10) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'unread',
  starred tinyint(1) NOT NULL DEFAULT 0,
  data longtext NOT NULL,
  meta longtext NULL,
  ip_hash varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY form_status_created (form_id,status,created_at),
  KEY created_at (created_at)
) $charset;",
			"CREATE TABLE {$p}entry_values (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  form_id bigint(20) unsigned NOT NULL,
  field_id varchar(64) NOT NULL,
  row_path varchar(191) NOT NULL DEFAULT '',
  value longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id),
  KEY form_field_value (form_id,field_id,value(100))
) $charset;",
			"CREATE TABLE {$p}entry_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  form_id bigint(20) unsigned NOT NULL,
  action_type varchar(40) NOT NULL,
  action_id varchar(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL,
  message text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id)
) $charset;",
		);
		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}
	}

	/** Drops all plugin tables (uninstall with opt-in only). */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( self::tables() as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- fixed, prefixed identifiers.
		}
	}
}
