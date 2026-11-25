/**
 * Rendu Markdown minimal en éléments React (aucun HTML injecté) :
 * titres, paragraphes, listes, blocs de code, `code`, **gras**, *italique*, liens http(s).
 */
import { createElement, Fragment } from '@wordpress/element';

const INLINE =
	/(`[^`]+`|\*\*[^*]+\*\*|\*[^*\s][^*]*\*|\[[^\]]+\]\(https?:\/\/[^)\s]+\))/g;

/**
 * Éléments en ligne.
 *
 * @param {string} text Texte.
 * @return {Array} Nœuds.
 */
function inline( text ) {
	const parts = text.split( INLINE );
	return parts.map( ( part, i ) => {
		if ( ! part ) {
			return null;
		}
		if ( part.startsWith( '`' ) && part.endsWith( '`' ) ) {
			return <code key={ i }>{ part.slice( 1, -1 ) }</code>;
		}
		if ( part.startsWith( '**' ) && part.endsWith( '**' ) ) {
			return <strong key={ i }>{ part.slice( 2, -2 ) }</strong>;
		}
		if (
			part.startsWith( '*' ) &&
			part.endsWith( '*' ) &&
			part.length > 2
		) {
			return <em key={ i }>{ part.slice( 1, -1 ) }</em>;
		}
		const link = part.match( /^\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)$/ );
		if ( link ) {
			return (
				<a
					key={ i }
					href={ link[ 2 ] }
					target="_blank"
					rel="noreferrer"
				>
					{ link[ 1 ] }
				</a>
			);
		}
		return <Fragment key={ i }>{ part }</Fragment>;
	} );
}

export default function Markdown( { text } ) {
	const lines = String( text || '' ).split( /\r?\n/ );
	const blocks = [];
	let i = 0;

	while ( i < lines.length ) {
		const line = lines[ i ];

		if ( line.trim().startsWith( '```' ) ) {
			const code = [];
			i++;
			while (
				i < lines.length &&
				! lines[ i ].trim().startsWith( '```' )
			) {
				code.push( lines[ i ] );
				i++;
			}
			i++;
			blocks.push(
				<pre key={ blocks.length }>
					<code>{ code.join( '\n' ) }</code>
				</pre>
			);
			continue;
		}

		const heading = line.match( /^(#{1,4})\s+(.*)$/ );
		if ( heading ) {
			const Tag = `h${ Math.min( 6, heading[ 1 ].length + 2 ) }`;
			blocks.push(
				<Tag key={ blocks.length }>{ inline( heading[ 2 ] ) }</Tag>
			);
			i++;
			continue;
		}

		if ( /^\s*([-*]|\d+[.)])\s+/.test( line ) ) {
			const ordered = /^\s*\d+[.)]\s+/.test( line );
			const items = [];
			while (
				i < lines.length &&
				/^\s*([-*]|\d+[.)])\s+/.test( lines[ i ] )
			) {
				items.push( lines[ i ].replace( /^\s*([-*]|\d+[.)])\s+/, '' ) );
				i++;
			}
			const List = ordered ? 'ol' : 'ul';
			blocks.push(
				<List key={ blocks.length }>
					{ items.map( ( item, n ) => (
						<li key={ n }>{ inline( item ) }</li>
					) ) }
				</List>
			);
			continue;
		}

		if ( ! line.trim() ) {
			i++;
			continue;
		}

		const paragraph = [];
		while (
			i < lines.length &&
			lines[ i ].trim() &&
			! /^(#{1,4})\s+/.test( lines[ i ] ) &&
			! /^\s*([-*]|\d+[.)])\s+/.test( lines[ i ] ) &&
			! lines[ i ].trim().startsWith( '```' )
		) {
			paragraph.push( lines[ i ] );
			i++;
		}
		blocks.push(
			<p key={ blocks.length }>
				{ paragraph.map( ( row, n ) => (
					<Fragment key={ n }>
						{ n > 0 && <br /> }
						{ inline( row ) }
					</Fragment>
				) ) }
			</p>
		);
	}

	return <div className="upsidly-chat-markdown">{ blocks }</div>;
}
