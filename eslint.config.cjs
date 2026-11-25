/**
 * Configuration ESLint : celle de @wordpress/scripts, avec le JSX en mode
 * classique (createElement / Fragment de @wordpress/element, cf. babel.config.js).
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		files: [ 'src/**/*.js' ],
		settings: {
			react: {
				pragma: 'createElement',
				fragment: 'Fragment',
			},
		},
		rules: {
			'react/jsx-uses-react': 'error',
		},
	},
];
