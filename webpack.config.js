/**
 * Extends the default @wordpress/scripts webpack config to also build the
 * network-settings admin app (a non-block entry) alongside the auto-detected
 * block scripts.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const baseEntry =
	typeof defaultConfig.entry === 'function'
		? defaultConfig.entry()
		: defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: {
		...baseEntry,
		'admin/settings': path.resolve( process.cwd(), 'src/admin/settings.js' ),
	},
};
