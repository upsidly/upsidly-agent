/**
 * Point d'entrée de la page « Agent ».
 */
import { createElement, createRoot, render } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import AgentApp from './AgentApp';
import './agent.scss';

domReady( () => {
	const container = document.getElementById( 'upsidly-agent-chat' );
	if ( ! container ) {
		return;
	}

	const app = <AgentApp config={ window.upsidlyAgentChat || {} } />;

	if ( typeof createRoot === 'function' ) {
		createRoot( container ).render( app );
	} else {
		render( app, container );
	}
} );
