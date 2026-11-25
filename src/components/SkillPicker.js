/**
 * Choix de la skill : une carte par skill de /status.
 */
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function SkillPicker( {
	skills,
	selected,
	onSelect,
	disabled,
} ) {
	if ( ! skills.length ) {
		return (
			<p>
				{ __(
					'Aucune fonctionnalité n’est disponible pour votre compte.',
					'upsidly-agent'
				) }
			</p>
		);
	}

	return (
		<div
			className="upsidly-agent-skills"
			role="radiogroup"
			aria-label={ __( 'Que voulez-vous faire ?', 'upsidly-agent' ) }
		>
			{ skills.map( ( skill ) => (
				<button
					key={ skill.id }
					type="button"
					role="radio"
					aria-checked={ selected === skill.id }
					className={
						'upsidly-agent-skill' +
						( selected === skill.id ? ' is-selected' : '' )
					}
					onClick={ () => onSelect( skill.id ) }
					disabled={ disabled }
				>
					<strong>{ skill.label }</strong>
					<span>{ skill.description }</span>
				</button>
			) ) }
		</div>
	);
}
