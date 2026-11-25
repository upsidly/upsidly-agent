/**
 * Différences entre la page d'origine et le brouillon de révision.
 */
import { createElement, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
} from '@wordpress/components';

import { ApplyBlock } from './ResultCard';

export default function PageDiff( { draftId, canApply, onClose } ) {
	const [ diff, setDiff ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		setDiff( null );
		setError( null );
		apiFetch( { path: `/upsidly-agent/v1/diff/${ draftId }` } )
			.then( setDiff )
			.catch( ( err ) =>
				setError(
					( err && err.message ) ||
						__(
							'Une erreur inattendue s’est produite.',
							'upsidly-agent'
						)
				)
			);
	}, [ draftId ] );

	return (
		<Card className="upsidly-agent-diff">
			<CardHeader>
				<h2>
					{ __(
						'Différences avec la page d’origine',
						'upsidly-agent'
					) }
				</h2>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Retour', 'upsidly-agent' ) }
				</Button>
			</CardHeader>
			<CardBody>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ ! error && ! diff && <Spinner /> }
				{ diff && (
					<div
						className="upsidly-agent-diff__table"
						// HTML produit par wp_text_diff() côté serveur (texte échappé).
						dangerouslySetInnerHTML={ { __html: diff.html } }
					/>
				) }
				{ diff && canApply && (
					<div className="upsidly-agent-actions">
						<ApplyBlock draftId={ draftId } />
					</div>
				) }
			</CardBody>
		</Card>
	);
}
