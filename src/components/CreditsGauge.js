/**
 * Jauge de crédits : utilisés / quota et date de renouvellement.
 */
import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Date AAAA-MM-JJ → date lisible.
 *
 * @param {string} isoDate Date.
 * @return {string} Date formatée.
 */
export function formatDate( isoDate ) {
	if ( ! isoDate ) {
		return '';
	}
	const date = new Date( isoDate + 'T00:00:00Z' );
	if ( Number.isNaN( date.getTime() ) ) {
		return isoDate;
	}
	return date.toLocaleDateString( document.documentElement.lang || 'fr', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: 'UTC',
	} );
}

export default function CreditsGauge( { credits } ) {
	if ( ! credits ) {
		return null;
	}

	const limit = Number( credits.limit ) || 0;
	const used = Number( credits.used ) || 0;
	const ratio = limit > 0 ? Math.min( 1, used / limit ) : 0;
	let level = 'ok';
	if ( ratio >= 1 ) {
		level = 'full';
	} else if ( ratio > 0.8 ) {
		level = 'warning';
	}

	return (
		<div className={ `upsidly-agent-gauge is-${ level }` }>
			<div className="upsidly-agent-gauge__label">
				<strong>
					{ sprintf(
						/* translators: 1: crédits utilisés, 2: quota mensuel. */
						__( '%1$s / %2$s crédits utilisés', 'upsidly-agent' ),
						used.toLocaleString(),
						limit.toLocaleString()
					) }
				</strong>
				{ credits.resets_on && (
					<span>
						{ sprintf(
							/* translators: %s: date de renouvellement. */
							__( 'Renouvellement le %s', 'upsidly-agent' ),
							formatDate( credits.resets_on )
						) }
					</span>
				) }
			</div>
			<div
				className="upsidly-agent-gauge__bar"
				role="progressbar"
				aria-valuemin={ 0 }
				aria-valuemax={ limit }
				aria-valuenow={ used }
			>
				<span style={ { width: `${ Math.round( ratio * 100 ) }%` } } />
			</div>
			{ level === 'full' && (
				<p className="upsidly-agent-gauge__note">
					{ __(
						'Vos crédits du mois sont épuisés : la génération est désactivée jusqu’au renouvellement.',
						'upsidly-agent'
					) }
				</p>
			) }
		</div>
	);
}
