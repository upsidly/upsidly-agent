/**
 * Application « Agent » : conversations, fil, saisie.
 */
import {
	createElement,
	Fragment,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';

import Thread from './Thread';

const API = '/upsidly-agent/v1/agent';

const SUGGESTIONS = [
	__( 'Mon site affiche une erreur', 'upsidly-agent' ),
	__( 'Mon site est lent', 'upsidly-agent' ),
	__( 'Mes extensions sont-elles à jour ?', 'upsidly-agent' ),
	__( 'Corriger un problème d’affichage', 'upsidly-agent' ),
	__( 'Améliorer le référencement d’une page', 'upsidly-agent' ),
	__( 'Fais un bilan de santé du site', 'upsidly-agent' ),
];

const STATUS_TEXT = {
	paused: __(
		'L’agent a atteint le nombre maximal d’étapes pour cette demande. Écrivez « Continue » pour qu’il poursuive.',
		'upsidly-agent'
	),
	stopped: __( 'Arrêté.', 'upsidly-agent' ),
	awaiting: __(
		'L’agent attend votre décision sur la modification proposée ci-dessus.',
		'upsidly-agent'
	),
	budget: __(
		'Le budget de cette conversation est épuisé. Commencez une nouvelle conversation pour continuer.',
		'upsidly-agent'
	),
};

/**
 * Message d'erreur lisible.
 *
 * @param {Object} error Erreur apiFetch.
 * @return {string} Message.
 */
function errorMessage( error ) {
	return (
		( error && error.message ) ||
		__( 'Une erreur inattendue s’est produite.', 'upsidly-agent' )
	);
}

/**
 * Ajoute des messages sans doublon (par identifiant).
 *
 * @param {Array} current Messages affichés.
 * @param {Array} added   Nouveaux messages.
 * @return {Array} Messages.
 */
function mergeMessages( current, added ) {
	const map = new Map( current.map( ( m ) => [ m.id, m ] ) );
	( added || [] ).forEach( ( m ) => map.set( m.id, m ) );
	return Array.from( map.values() ).sort( ( a, b ) => a.id - b.id );
}

export default function AgentApp( { config } ) {
	const [ ack, setAck ] = useState( !! config.ack );
	const [ status, setStatus ] = useState( null );
	const [ statusError, setStatusError ] = useState( null );
	const [ conversations, setConversations ] = useState( [] );
	const [ currentId, setCurrentId ] = useState( null );
	const [ messages, setMessages ] = useState( [] );
	const [ convStatus, setConvStatus ] = useState( 'idle' );
	const [ convCredits, setConvCredits ] = useState( 0 );
	const [ actions, setActions ] = useState( [] );
	const [ deciding, setDeciding ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ draft, setDraft ] = useState( '' );
	const stopRef = useRef( false );
	const endRef = useRef( null );

	const credits = status && status.credits;
	const agent = ( status && status.agent ) || {};
	const budget = agent.budget || 200;

	const loadConversations = useCallback( () => {
		apiFetch( { path: `${ API }/conversations` } )
			.then( setConversations )
			.catch( () => {} );
	}, [] );

	const loadStatus = useCallback( ( refresh = false ) => {
		apiFetch( {
			path: '/upsidly-agent/v1/status' + ( refresh ? '?refresh=1' : '' ),
		} )
			.then( setStatus )
			.catch( ( e ) => setStatusError( errorMessage( e ) ) );
	}, [] );

	useEffect( () => {
		loadStatus( true );
		loadConversations();
	}, [ loadStatus, loadConversations ] );

	useEffect( () => {
		if ( endRef.current && endRef.current.scrollIntoView ) {
			endRef.current.scrollIntoView( {
				behavior: 'smooth',
				block: 'end',
			} );
		}
	}, [ messages, busy ] );

	const runLoop = useCallback(
		async ( id ) => {
			stopRef.current = false;
			setBusy( true );
			setError( null );
			try {
				for ( let guard = 0; guard < 200; guard++ ) {
					if ( stopRef.current ) {
						break;
					}
					const res = await apiFetch( {
						path: `${ API }/conversations/${ id }/step`,
						method: 'POST',
					} );
					setMessages( ( current ) =>
						mergeMessages( current, res.messages )
					);
					if ( res.actions ) {
						setActions( res.actions );
					}
					setConvStatus( res.status );
					if (
						res.conversation &&
						res.conversation.credits_used !== undefined
					) {
						setConvCredits( res.conversation.credits_used );
					}
					if ( res.credits ) {
						setStatus( ( current ) =>
							current
								? { ...current, credits: res.credits }
								: current
						);
					}
					if ( res.error ) {
						setError( res.error.message );
					}
					if ( res.status !== 'running' ) {
						break;
					}
				}
			} catch ( e ) {
				setError( errorMessage( e ) );
			} finally {
				setBusy( false );
				loadConversations();
			}
		},
		[ loadConversations ]
	);

	const openConversation = ( id ) => {
		if ( busy ) {
			return;
		}
		setError( null );
		apiFetch( { path: `${ API }/conversations/${ id }` } )
			.then( ( res ) => {
				setCurrentId( id );
				setMessages( res.messages || [] );
				setActions( res.actions || [] );
				setConvStatus(
					res.conversation ? res.conversation.status : 'idle'
				);
				setConvCredits(
					res.conversation ? res.conversation.credits : 0
				);
				if (
					res.conversation &&
					res.conversation.status === 'running'
				) {
					runLoop( id );
				}
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	};

	const newConversation = () => {
		if ( busy ) {
			return;
		}
		setCurrentId( null );
		setMessages( [] );
		setActions( [] );
		setConvStatus( 'idle' );
		setConvCredits( 0 );
		setError( null );
	};

	const decide = ( action, decision, comment = '' ) => {
		if ( deciding ) {
			return;
		}
		setDeciding( true );
		setError( null );
		apiFetch( {
			path: `${ API }/actions/${ action.id }/decide`,
			method: 'POST',
			data: { decision, comment },
		} )
			.then( ( res ) => {
				setActions( res.actions || [] );
				setConvStatus( res.status );
				if ( res.status === 'running' && currentId ) {
					runLoop( currentId );
				}
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setDeciding( false ) );
	};

	const undo = ( action ) => {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Annuler cette modification et revenir à l’état précédent ?',
					'upsidly-agent'
				)
			)
		) {
			return;
		}
		setDeciding( true );
		apiFetch( {
			path: `${ API }/actions/${ action.id }/undo`,
			method: 'POST',
		} )
			.then( ( res ) => setActions( res.actions || [] ) )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setDeciding( false ) );
	};

	const undoAll = () => {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Annuler toutes les modifications appliquées dans cette conversation, de la plus récente à la plus ancienne ?',
					'upsidly-agent'
				)
			)
		) {
			return;
		}
		setDeciding( true );
		apiFetch( {
			path: `${ API }/conversations/${ currentId }/undo-all`,
			method: 'POST',
		} )
			.then( ( res ) => {
				setActions( res.actions || [] );
				if ( res.errors && res.errors.length ) {
					setError( res.errors.join( ' · ' ) );
				}
			} )
			.catch( ( e ) => setError( errorMessage( e ) ) )
			.finally( () => setDeciding( false ) );
	};

	const send = ( text ) => {
		const message = ( text || '' ).trim();
		if ( ! message || busy ) {
			return;
		}
		setDraft( '' );
		setError( null );
		setBusy( true );

		const request = currentId
			? apiFetch( {
					path: `${ API }/conversations/${ currentId }/messages`,
					method: 'POST',
					data: { message },
				} )
			: apiFetch( {
					path: `${ API }/conversations`,
					method: 'POST',
					data: { message },
				} );

		request
			.then( ( res ) => {
				const id = res.conversation.id;
				setCurrentId( id );
				setMessages( res.messages || [] );
				setConvStatus( res.conversation.status );
				runLoop( id );
			} )
			.catch( ( e ) => {
				setDraft( message );
				setError( errorMessage( e ) );
				setBusy( false );
			} );
	};

	const stop = () => {
		stopRef.current = true;
		if ( currentId ) {
			apiFetch( {
				path: `${ API }/conversations/${ currentId }/stop`,
				method: 'POST',
			} )
				.then( ( res ) => {
					setConvStatus( 'stopped' );
					if ( res && res.actions ) {
						setActions( res.actions );
					}
				} )
				.catch( () => {} );
		}
	};

	const acknowledge = () => {
		apiFetch( { path: `${ API }/ack`, method: 'POST' } )
			.then( () => setAck( true ) )
			.catch( ( e ) => setError( errorMessage( e ) ) );
	};

	const onKeyDown = ( event ) => {
		if ( event.key === 'Enter' && ! event.shiftKey ) {
			event.preventDefault();
			send( draft );
		}
	};

	const agentOff = status && agent.level === 'off';
	const quotaFull =
		credits && credits.limit > 0 && credits.used >= credits.limit;

	return (
		<div className="upsidly-chat">
			<aside className="upsidly-chat__side">
				<Button
					variant="primary"
					className="upsidly-chat__new"
					onClick={ newConversation }
					disabled={ busy }
				>
					{ __( '+ Nouvelle conversation', 'upsidly-agent' ) }
				</Button>
				<div className="upsidly-chat__list">
					<p className="upsidly-chat__list-title">
						{ __( 'Conversations', 'upsidly-agent' ) }
					</p>
					{ conversations.length === 0 && (
						<p className="upsidly-chat__empty">
							{ __( 'Aucune conversation.', 'upsidly-agent' ) }
						</p>
					) }
					{ conversations.map( ( conv ) => (
						<button
							key={ conv.id }
							type="button"
							className={
								'upsidly-chat__conv' +
								( conv.id === currentId ? ' is-active' : '' )
							}
							onClick={ () => openConversation( conv.id ) }
							disabled={ busy }
						>
							{ conv.title ||
								__( '(sans titre)', 'upsidly-agent' ) }
						</button>
					) ) }
				</div>
				{ credits && (
					<div className="upsidly-chat__credits">
						<strong>
							{ sprintf(
								/* translators: %s: crédits restants. */
								__( '%s crédits restants', 'upsidly-agent' ),
								Number( credits.remaining ).toLocaleString()
							) }
						</strong>
						<span>
							{ sprintf(
								/* translators: %s: date de renouvellement. */
								__( 'Renouvellement le %s', 'upsidly-agent' ),
								credits.resets_on
							) }
						</span>
					</div>
				) }
			</aside>

			<main className="upsidly-chat__main">
				<div className="upsidly-chat__scroll">
					{ statusError && (
						<Notice status="error" isDismissible={ false }>
							{ statusError }
						</Notice>
					) }
					{ agentOff && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'L’agent n’est pas encore activé pour ce site par Upsidly. Contactez Upsidly pour l’activer.',
								'upsidly-agent'
							) }
						</Notice>
					) }
					{ ! ack && (
						<Notice status="info" isDismissible={ false }>
							<p>
								<strong>
									{ __(
										'Avant de commencer',
										'upsidly-agent'
									) }
								</strong>
							</p>
							<p>
								{ __(
									'L’agent lit les fichiers des thèmes et des extensions, les journaux d’erreurs, les réglages, les contenus et la base de données de ce site pour diagnostiquer vos problèmes. Ces informations sont transmises à Upsidly et à son fournisseur d’IA (Anthropic). Les mots de passe, clés et données personnelles sont masqués avant tout envoi. L’agent peut proposer des modifications (fichiers, extensions, réglages, contenus) : rien n’est modifié sans votre clic sur « Appliquer », chaque modification est sauvegardée et peut être annulée.',
									'upsidly-agent'
								) }
							</p>
							<Button variant="primary" onClick={ acknowledge }>
								{ __( 'J’ai compris', 'upsidly-agent' ) }
							</Button>
						</Notice>
					) }

					{ messages.length === 0 ? (
						<div className="upsidly-chat__welcome">
							<h1>
								{ sprintf(
									/* translators: %s: nom du site. */
									__(
										'Comment puis-je vous aider sur %s ?',
										'upsidly-agent'
									),
									config.siteName
								) }
							</h1>
							<div className="upsidly-chat__suggestions">
								{ SUGGESTIONS.map( ( text ) => (
									<button
										key={ text }
										type="button"
										onClick={ () => send( text ) }
										disabled={
											busy ||
											! ack ||
											agentOff ||
											quotaFull
										}
									>
										{ text }
									</button>
								) ) }
							</div>
						</div>
					) : (
						<Thread
							messages={ messages }
							actions={ actions }
							onDecide={ decide }
							onUndo={ undo }
							busy={ deciding || busy }
						/>
					) }

					{ busy && (
						<div className="upsidly-chat__working">
							<Spinner />
							{ __( 'L’agent travaille…', 'upsidly-agent' ) }
						</div>
					) }
					{ ! busy &&
						STATUS_TEXT[ convStatus ] &&
						messages.length > 0 && (
							<p className="upsidly-chat__status">
								{ STATUS_TEXT[ convStatus ] }
							</p>
						) }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<div ref={ endRef } />
				</div>

				<div className="upsidly-chat__composer">
					<textarea
						value={ draft }
						onChange={ ( event ) => setDraft( event.target.value ) }
						onKeyDown={ onKeyDown }
						placeholder={ __(
							'Décrivez votre problème ou votre demande…',
							'upsidly-agent'
						) }
						rows={ 3 }
						maxLength={ 8000 }
						disabled={ ! ack || agentOff || quotaFull }
						aria-label={ __( 'Votre message', 'upsidly-agent' ) }
					/>
					<div className="upsidly-chat__composer-bar">
						{ actions.some( ( action ) => action.can_undo ) && (
							<Button
								variant="link"
								isDestructive
								onClick={ undoAll }
								disabled={ busy || deciding }
							>
								{ __( 'Tout annuler', 'upsidly-agent' ) }
							</Button>
						) }
						<span className="upsidly-chat__meta">
							{ currentId && (
								<Fragment>
									{ sprintf(
										/* translators: 1: crédits utilisés, 2: budget. */
										__(
											'Conversation : %1$s / %2$s crédits',
											'upsidly-agent'
										),
										convCredits,
										budget
									) }
								</Fragment>
							) }
						</span>
						{ busy ? (
							<Button variant="secondary" onClick={ stop }>
								{ __( 'Arrêter', 'upsidly-agent' ) }
							</Button>
						) : (
							<Button
								variant="primary"
								onClick={ () => send( draft ) }
								disabled={
									! draft.trim() ||
									! ack ||
									agentOff ||
									quotaFull
								}
							>
								{ __( 'Envoyer', 'upsidly-agent' ) }
							</Button>
						) }
					</div>
				</div>
			</main>
		</div>
	);
}
