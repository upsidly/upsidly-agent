/**
 * Fil de conversation : messages de l'utilisateur, réponses et étapes de l'agent.
 */
import { createElement } from '@wordpress/element';

import Markdown from './Markdown';
import ToolStep, { toolLabel } from './ToolStep';
import ActionCard from './ActionCard';

/**
 * Blocs d'un contenu (texte simple → bloc texte).
 *
 * @param {string|Array} content Contenu.
 * @return {Array} Blocs.
 */
function blocksOf( content ) {
	if ( typeof content === 'string' ) {
		return [ { type: 'text', text: content } ];
	}
	return Array.isArray( content ) ? content : [];
}

export default function Thread( {
	messages,
	actions = [],
	onDecide,
	onUndo,
	busy,
} ) {
	const byUse = {};
	actions.forEach( ( action ) => {
		byUse[ action.tool_use_id ] = action;
	} );

	const results = {};
	messages.forEach( ( message ) => {
		if ( message.role !== 'user' ) {
			return;
		}
		blocksOf( message.content ).forEach( ( block ) => {
			if ( block.type === 'tool_result' ) {
				results[ block.tool_use_id ] = block;
			}
		} );
	} );

	const items = [];
	messages.forEach( ( message ) => {
		blocksOf( message.content ).forEach( ( block, index ) => {
			const key = `${ message.id }-${ index }`;
			if ( block.type === 'text' && block.text && block.text.trim() ) {
				items.push(
					message.role === 'user' ? (
						<div key={ key } className="upsidly-chat-msg is-user">
							<div className="upsidly-chat-msg__bubble">
								{ block.text }
							</div>
						</div>
					) : (
						<div key={ key } className="upsidly-chat-msg is-agent">
							<Markdown text={ block.text } />
						</div>
					)
				);
			} else if ( block.type === 'tool_use' && byUse[ block.id ] ) {
				items.push(
					<ActionCard
						key={ key }
						action={ byUse[ block.id ] }
						fallbackTitle={ toolLabel( block ) }
						onDecide={ onDecide }
						onUndo={ onUndo }
						busy={ busy }
					/>
				);
			} else if ( block.type === 'tool_use' ) {
				items.push(
					<ToolStep
						key={ key }
						use={ block }
						result={ results[ block.id ] }
					/>
				);
			}
		} );
	} );

	return <div className="upsidly-chat-thread">{ items }</div>;
}
