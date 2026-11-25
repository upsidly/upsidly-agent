/**
 * Étape d'outil : demande de l'agent et résultat, repliable.
 */
import { createElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Libellé lisible d'une demande d'outil.
 *
 * @param {Object} use Bloc tool_use.
 * @return {string} Libellé.
 */
export function toolLabel( use ) {
	const input = use.input || {};
	switch ( use.name ) {
		case 'site_overview':
			return __( 'Vue d’ensemble du site', 'upsidly-agent' );
		case 'read_logs':
			return __( 'Lecture des journaux d’erreurs', 'upsidly-agent' );
		case 'fetch_page':
			return sprintf(
				/* translators: %s: chemin de la page. */
				__( 'Chargement de la page %s', 'upsidly-agent' ),
				input.path || '/'
			);
		case 'list_files':
			return sprintf(
				/* translators: %s: dossier. */
				__( 'Liste du dossier %s', 'upsidly-agent' ),
				'wp-content/' + ( input.path || '' )
			);
		case 'read_file':
			if ( input.start_line ) {
				return sprintf(
					/* translators: 1: fichier, 2: première ligne, 3: dernière ligne. */
					__(
						'Lecture de %1$s (lignes %2$s à %3$s)',
						'upsidly-agent'
					),
					input.path,
					input.start_line,
					input.end_line || '…'
				);
			}
			return sprintf(
				/* translators: %s: fichier. */
				__( 'Lecture de %s', 'upsidly-agent' ),
				input.path
			);
		case 'search_files':
			return sprintf(
				/* translators: %s: texte recherché. */
				__( 'Recherche de « %s » dans les fichiers', 'upsidly-agent' ),
				input.query
			);
		case 'get_options':
			return __( 'Lecture de réglages', 'upsidly-agent' );
		case 'list_content':
			return sprintf(
				/* translators: %s: type de contenu. */
				__( 'Liste des contenus (%s)', 'upsidly-agent' ),
				input.post_type || 'post'
			);
		case 'get_content':
			return sprintf(
				/* translators: %s: identifiant. */
				__( 'Lecture du contenu n°%s', 'upsidly-agent' ),
				input.id
			);
		case 'db_query_read':
			return __(
				'Requête en lecture sur la base de données',
				'upsidly-agent'
			);
		default:
			return use.name;
	}
}

export default function ToolStep( { use, result } ) {
	const [ open, setOpen ] = useState( false );
	let state = 'pending';
	if ( result ) {
		state = result.is_error ? 'error' : 'done';
	}

	return (
		<div className={ `upsidly-chat-tool is-${ state }` }>
			<button
				type="button"
				className="upsidly-chat-tool__head"
				onClick={ () => setOpen( ! open ) }
				aria-expanded={ open }
			>
				<span className="upsidly-chat-tool__icon" aria-hidden="true">
					{ state === 'done' && '✓' }
					{ state === 'error' && '!' }
					{ state === 'pending' && '…' }
				</span>
				<span className="upsidly-chat-tool__label">
					{ toolLabel( use ) }
				</span>
				<span className="upsidly-chat-tool__toggle" aria-hidden="true">
					{ open ? '▾' : '▸' }
				</span>
			</button>
			{ open && (
				<div className="upsidly-chat-tool__body">
					{ use.name === 'db_query_read' && use.input?.sql && (
						<pre>
							<code>{ use.input.sql }</code>
						</pre>
					) }
					{ result ? (
						<pre className="upsidly-chat-tool__result">
							{ typeof result.content === 'string'
								? result.content
								: JSON.stringify( result.content, null, 2 ) }
						</pre>
					) : (
						<p>{ __( 'En cours…', 'upsidly-agent' ) }</p>
					) }
				</div>
			) }
		</div>
	);
}
