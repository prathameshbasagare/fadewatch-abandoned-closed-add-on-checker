<?php
/**
 * Works out which WordPress.org plugin each installed plugin corresponds to.
 *
 * @package AbandonedPluginChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolver.
 */
final class ABPC_Resolver {

	/**
	 * Returns every installed plugin with its WordPress.org slug.
	 *
	 * Each item has a `skip` reason; an empty string means "check it":
	 * - `update_uri`: the Update URI header points somewhere other than WordPress.org.
	 * - `inactive`:   inactive plugins are excluded in the settings.
	 * - `filtered`:   excluded through the `abpc_skip_plugin` filter.
	 * - `no_slug`:    no usable slug could be derived.
	 *
	 * @return array<string, array> Keyed by plugin file.
	 */
	public static function get_items() {
		self::load_plugin_functions();

		$include_inactive = (bool) ABPC_Settings::get( 'include_inactive' );
		$items            = array();

		foreach ( get_plugins() as $file => $data ) {
			$active = is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) );
			$slug   = self::slug_for( $file, $data );

			$item = array(
				'file'       => $file,
				'slug'       => $slug,
				'name'       => isset( $data['Name'] ) ? (string) $data['Name'] : $file,
				'version'    => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'author'     => isset( $data['Author'] ) ? (string) $data['Author'] : '',
				'plugin_uri' => isset( $data['PluginURI'] ) ? (string) $data['PluginURI'] : '',
				'active'     => $active,
				'skip'       => '',
			);

			/**
			 * Filters whether a plugin is excluded from checks.
			 *
			 * @param bool   $skip Whether to skip the plugin.
			 * @param string $file Plugin file, e.g. `akismet/akismet.php`.
			 * @param array  $data Plugin header data.
			 */
			$skip = (bool) apply_filters( 'abpc_skip_plugin', false, $file, $data );

			if ( $skip ) {
				$item['skip'] = 'filtered';
			} elseif ( self::has_foreign_update_uri( $data ) ) {
				$item['skip'] = 'update_uri';
			} elseif ( '' === $slug ) {
				$item['skip'] = 'no_slug';
			} elseif ( ! $active && ! $include_inactive ) {
				$item['skip'] = 'inactive';
			}

			$items[ $file ] = $item;
		}

		return $items;
	}

	/**
	 * Files of the plugins that need an API call.
	 *
	 * @return string[]
	 */
	public static function checkable_files() {
		$files = array();
		foreach ( self::get_items() as $file => $item ) {
			if ( '' === $item['skip'] ) {
				$files[] = $file;
			}
		}
		return $files;
	}

	/**
	 * Must-use plugins and drop-ins, which are listed but never checked.
	 *
	 * @return array<int, array{name:string, file:string, type:string}>
	 */
	public static function unchecked_extras() {
		self::load_plugin_functions();

		$extras = array();
		foreach ( get_mu_plugins() as $file => $data ) {
			$extras[] = array(
				'name' => $data['Name'] ? $data['Name'] : $file,
				'file' => $file,
				'type' => 'mustuse',
			);
		}
		foreach ( get_dropins() as $file => $data ) {
			$extras[] = array(
				'name' => $data['Name'] ? $data['Name'] : $file,
				'file' => $file,
				'type' => 'dropin',
			);
		}
		return $extras;
	}

	/**
	 * Derives the WordPress.org slug for a plugin file.
	 *
	 * @param string $file Plugin file.
	 * @param array  $data Plugin header data.
	 * @return string Empty string when no valid slug can be derived.
	 */
	public static function slug_for( $file, array $data = array() ) {
		if ( false === strpos( $file, '/' ) ) {
			// Single-file plugin in the plugins folder.
			$slug = basename( $file, '.php' );
			if ( 'hello' === $slug ) {
				$slug = 'hello-dolly';
			}
		} else {
			$slug = strtok( $file, '/' );
		}

		/**
		 * Filters the WordPress.org slug used for a plugin.
		 *
		 * @param string $slug Derived slug.
		 * @param string $file Plugin file.
		 * @param array  $data Plugin header data.
		 */
		$slug = apply_filters( 'abpc_plugin_slug', $slug, $file, $data );

		$slug = strtolower( (string) $slug );
		return preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $slug ) ? $slug : '';
	}

	/**
	 * Whether the Update URI header points away from WordPress.org,
	 * mirroring how core decides it in wp_update_plugins().
	 *
	 * @param array $data Plugin header data.
	 * @return bool
	 */
	public static function has_foreign_update_uri( array $data ) {
		if ( empty( $data['UpdateURI'] ) ) {
			return false;
		}
		$host = wp_parse_url( sanitize_url( $data['UpdateURI'] ), PHP_URL_HOST );
		return ! in_array( $host, array( 'wordpress.org', 'w.org' ), true );
	}

	/**
	 * Makes sure plugin helper functions exist (they don't in cron or REST).
	 */
	private static function load_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
