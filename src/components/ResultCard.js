/**
 * Carte de résultat d'une génération.
 */
import { createElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';

/**
 * Bouton « Appliquer à la page » et retour d'information.
 *
 * @param {Object} props         Propriétés.
 * @param {number} props.draftId Brouillon de révision.
 * @return {Element} Élément.
 */
export function ApplyBlock( { draftId } ) {
	const [ busy, setBusy ] = useState( false );
	const [ applied, setApplied ] = useState( null );
	const [ error, setError ] = useState( null );

	const apply = () => {
		if ( busy ) {
			return;
		}
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__(
				'Remplacer le contenu de la page d’origine par cette révision ? L’état actuel restera disponible dans les révisions de la page.',
				'upsidly-agent'
			)
		);
		if ( ! confirmed ) {
			return;
		}
		setBusy( true );
		setError( null );
		apiFetch( {
			path: `/upsidly-agent/v1/apply/${ draftId }`,
			method: 'POST',
		} )
			.then( setApplied )
			.catch( ( err ) =>
				setError(
					( err && err.message ) ||
						__(
							'Une erreur inattendue s’est produite.',
							'upsidly-agent'
						)
				)
			)
			.finally( () => setBusy( false ) );
	};

	if ( applied ) {
		return (
			<Notice status="success" isDismissible={ false }>
				<p>{ __( 'La page a été mise à jour.', 'upsidly-agent' ) }</p>
				<p className="upsidly-agent-links">
					<a
						href={ applied.view_url }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Voir la page', 'upsidly-agent' ) }
					</a>
					<a href={ applied.edit_url }>
						{ __( 'Modifier la page', 'upsidly-agent' ) }
					</a>
					{ applied.revisions_url && (
						<a href={ applied.revisions_url }>
							{ __(
								'Revenir à la version précédente (révisions)',
								'upsidly-agent'
							) }
						</a>
					) }
				</p>
			</Notice>
		);
	}

	return (
		<span className="upsidly-agent-apply">
			<Button
				variant="primary"
				onClick={ apply }
				isBusy={ busy }
				disabled={ busy }
			>
				{ __( 'Appliquer à la page', 'upsidly-agent' ) }
			</Button>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</span>
	);
}

function Row( { label, children } ) {
	if ( ! children || ( Array.isArray( children ) && ! children.length ) ) {
		return null;
	}
	return (
		<div className="upsidly-agent-row">
			<span className="upsidly-agent-row__label">{ label }</span>
			<span className="upsidly-agent-row__value">{ children }</span>
		</div>
	);
}

export default function ResultCard( {
	result,
	skillId,
	canApply,
	onShowDiff,
} ) {
	const data = result.result || {};
	const isRevision = !! result.source_page_id;
	const charged = result.credits ? result.credits.charged : 0;

	return (
		<Card className="upsidly-agent-result">
			<CardHeader>
				<h2>{ data.titre }</h2>
			</CardHeader>
			<CardBody>
				{ data.notes && (
					<Notice status="warning" isDismissible={ false }>
						<strong>{ __( 'À vérifier', 'upsidly-agent' ) }</strong>
						<p className="upsidly-agent-notes">{ data.notes }</p>
					</Notice>
				) }

				<Row
					label={
						isRevision
							? __( 'Changements', 'upsidly-agent' )
							: __( 'Extrait', 'upsidly-agent' )
					}
				>
					{ isRevision ? data.resume_changements : data.extrait }
				</Row>
				<Row label={ __( 'Méta-description', 'upsidly-agent' ) }>
					{ data.meta_description }
				</Row>
				{ skillId === 'rediger-article' && (
					<Row label={ __( 'Catégorie', 'upsidly-agent' ) }>
						{ data.categorie }
					</Row>
				) }
				{ skillId === 'rediger-article' && (
					<Row label={ __( 'Étiquettes', 'upsidly-agent' ) }>
						{ ( data.etiquettes || [] ).join( ', ' ) }
					</Row>
				) }
				<Row label={ __( 'Crédits consommés', 'upsidly-agent' ) }>
					{ String( charged || 0 ) }
				</Row>

				<h3>{ __( 'Aperçu', 'upsidly-agent' ) }</h3>
				<div
					className="upsidly-agent-preview"
					// Contenu nettoyé côté serveur (blocs autorisés + wp_kses_post) puis rendu par do_blocks().
					dangerouslySetInnerHTML={ {
						__html: data.contenu_html || '',
					} }
				/>

				<div className="upsidly-agent-actions">
					<Button variant="primary" href={ result.edit_url }>
						{ __( 'Ouvrir le brouillon', 'upsidly-agent' ) }
					</Button>
					<Button
						variant="secondary"
						href={ result.preview_url }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Prévisualiser', 'upsidly-agent' ) }
					</Button>
					{ isRevision && (
						<Button variant="secondary" onClick={ onShowDiff }>
							{ __( 'Voir les différences', 'upsidly-agent' ) }
						</Button>
					) }
					{ isRevision && canApply && (
						<ApplyBlock draftId={ result.post_id } />
					) }
				</div>
				{ isRevision && ! canApply && (
					<p className="description">
						{ sprintf(
							/* translators: %s: nom du droit WordPress. */
							__(
								'Seul un utilisateur pouvant publier des pages (%s) peut appliquer la révision.',
								'upsidly-agent'
							),
							'publish_pages'
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}
