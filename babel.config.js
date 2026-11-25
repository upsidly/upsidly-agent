/**
 * JSX en mode classique (createElement de @wordpress/element) : le script
 * « react-jsx-runtime » n'existe qu'à partir de WordPress 6.6, et le plugin
 * vise WordPress 6.4+.
 *
 * @param {Object} api API Babel.
 * @return {Object} Configuration Babel.
 */
module.exports = ( api ) => {
	api.cache( true );
	return {
		presets: [ '@wordpress/babel-preset-default' ],
		plugins: [
			[
				'@babel/plugin-transform-react-jsx',
				{
					runtime: 'classic',
					pragma: 'createElement',
					pragmaFrag: 'Fragment',
				},
			],
		],
	};
};
