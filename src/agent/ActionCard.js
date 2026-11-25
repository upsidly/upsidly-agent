/**
 * Carte d'action d'écriture : aperçu, validation, annulation.
 */
import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

const STATUS = {
	pending: __( 'En attente de votre validation', 'upsidly-agent' ),
	applying: __( 'Application en cours…', 'upsidly-agent' ),
	applied: __( 'Appliqué', 'upsidly-agent' ),
	rejected: __( 'Refusé', 'upsidly-agent' ),
	failed: __( 'Non appliqué', 'upsidly-agent' ),
	reverted: __(
		'Annulé automatiquement : le site tombait en erreur',
		'upsidly-agent'
	),
	undone: __( 'Annulé', 'upsidly-agent' ),
};

export default function ActionCard( {
	action,
	fallbackTitle,
	onDecide,
	onUndo,
	busy,
} ) {
	const [ rejecting, setRejecting ] = useState( false );
	const [ comment, setComment ] = useState( '' );
	const [ showPreview, setShowPreview ] = useState(
		action.status === 'pending'
	);
	const pending = action.status === 'pending';

	return (
		<div className={ `upsidly-chat-action is-${ action.status }` }>
			<div className="upsidly-chat-action__head">
				<strong>{ action.title || fallbackTitle }</strong>
				<span className="upsidly-chat-action__status">
					{ STATUS[ action.status ] || action.status }
				</span>
			</div>

			{ action.reason && (
				<p className="upsidly-chat-action__reason">{ action.reason }</p>
			) }

			{ action.preview && (
				<div className="upsidly-chat-action__preview">
					<button
						type="button"
						className="upsidly-chat-action__toggle"
						onClick={ () => setShowPreview( ! showPreview ) }
						aria-expanded={ showPreview }
					>
						{ showPreview
							? __( 'Masquer le détail', 'upsidly-agent' )
							: __(
									'Voir le détail de la modification',
									'upsidly-agent'
								) }
					</button>
					{ showPreview && (
						<div
							className="upsidly-chat-action__diff"
							// HTML produit par wp_text_diff() côté serveur (texte échappé).
							dangerouslySetInnerHTML={ {
								__html: action.preview,
							} }
						/>
					) }
				</div>
			) }

			{ ! pending && action.result && action.status !== 'applied' && (
				<p className="upsidly-chat-action__result">{ action.result }</p>
			) }

			{ pending && ! rejecting && (
				<div className="upsidly-chat-action__buttons">
					<Button
						variant="primary"
						onClick={ () => onDecide( action, 'approve' ) }
						isBusy={ busy }
						disabled={ busy }
					>
						{ __( 'Appliquer', 'upsidly-agent' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => setRejecting( true ) }
						disabled={ busy }
					>
						{ __( 'Refuser', 'upsidly-agent' ) }
					</Button>
				</div>
			) }

			{ pending && rejecting && (
				<div className="upsidly-chat-action__reject">
					<textarea
						value={ comment }
						onChange={ ( event ) =>
							setComment( event.target.value )
						}
						placeholder={ __(
							'Pourquoi ? Que préférez-vous ? (facultatif)',
							'upsidly-agent'
						) }
						rows={ 2 }
						maxLength={ 2000 }
					/>
					<div className="upsidly-chat-action__buttons">
						<Button
							variant="secondary"
							isDestructive
							onClick={ () =>
								onDecide( action, 'reject', comment )
							}
							disabled={ busy }
						>
							{ __( 'Confirmer le refus', 'upsidly-agent' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setRejecting( false ) }
							disabled={ busy }
						>
							{ __( 'Retour', 'upsidly-agent' ) }
						</Button>
					</div>
				</div>
			) }

			{ action.can_undo && (
				<div className="upsidly-chat-action__buttons">
					<Button
						variant="link"
						onClick={ () => onUndo( action ) }
						disabled={ busy }
					>
						{ __( 'Annuler cette modification', 'upsidly-agent' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
