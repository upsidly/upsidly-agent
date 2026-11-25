/**
 * Point d'entrée de l'Assistant.
 */
import { createElement, createRoot, render } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import App from './App';
import './style.scss';

domReady( () => {
	const container = document.getElementById( 'upsidly-agent-app' );
	if ( ! container ) {
		return;
	}

	const app = <App config={ window.upsidlyAgent || {} } />;

	if ( typeof createRoot === 'function' ) {
		createRoot( container ).render( app );
	} else {
		render( app, container );
	}
} );
