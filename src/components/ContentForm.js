/**
 * Formulaire de « Rédiger un contenu » : type de contenu, demande, statut, taxonomies.
 */
import { createElement, Fragment, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

/**
 * Regroupe une liste par libellé d'origine, dans l'ordre d'apparition.
 *
 * @param {Array} items Éléments avec originLabel.
 * @return {Array} [ [ libellé, éléments ], … ]
 */
function byOrigin( items ) {
	const groups = new Map();
	items.forEach( ( item ) => {
		if ( ! groups.has( item.originLabel ) ) {
			groups.set( item.originLabel, [] );
		}
		groups.get( item.originLabel ).push( item );
	} );
	return Array.from( groups.entries() );
}

/**
 * Valeurs initiales pour « Rédiger un contenu ».
 *
 * @param {Object} skill Skill.
 * @param {Array}  types Types de contenu disponibles.
 * @return {Object} Valeurs.
 */
export function contentInitialValues( skill, types ) {
	const longueur = ( skill.inputs && skill.inputs.longueur ) || {};
	return {
		type_contenu: types.length ? types[ 0 ].slug : '',
		demande: '',
		mot_cle: '',
		longueur: longueur.default || 800,
		__status: 'draft',
		__terms: {},
	};
}

function TaxonomyBox( { taxonomy, selected, onChange, disabled } ) {
	const [ filter, setFilter ] = useState( '' );
	const terms = taxonomy.terms || [];
	const shown = filter
		? terms.filter( ( term ) =>
				term.name.toLowerCase().includes( filter.toLowerCase() )
			)
		: terms;

	const toggle = ( id, checked ) =>
		onChange(
			checked
				? [ ...selected, id ]
				: selected.filter( ( value ) => value !== id )
		);

	return (
		<fieldset className="upsidly-agent-taxonomy">
			<legend>
				{ taxonomy.label }
				{ selected.length > 0 && (
					<span className="upsidly-agent-taxonomy__count">
						{ sprintf(
							/* translators: %d: nombre de termes cochés. */
							__( '%d coché(s)', 'upsidly-agent' ),
							selected.length
						) }
					</span>
				) }
			</legend>
			{ terms.length > 12 && (
				<TextControl
					placeholder={ __( 'Filtrer…', 'upsidly-agent' ) }
					value={ filter }
					onChange={ setFilter }
					disabled={ disabled }
					__nextHasNoMarginBottom
				/>
			) }
			{ terms.length === 0 ? (
				<p className="description">
					{ __(
						'Aucun terme : l’IA pourra en proposer.',
						'upsidly-agent'
					) }
				</p>
			) : (
				<div className="upsidly-agent-taxonomy__terms">
					{ shown.map( ( term ) => (
						<CheckboxControl
							key={ term.id }
							label={ term.name }
							checked={ selected.includes( term.id ) }
							onChange={ ( checked ) =>
								toggle( term.id, checked )
							}
							disabled={ disabled }
							__nextHasNoMarginBottom
						/>
					) ) }
				</div>
			) }
		</fieldset>
	);
}

export default function ContentForm( {
	skill,
	values,
	onChange,
	types,
	disabled,
} ) {
	const type = types.find( ( item ) => item.slug === values.type_contenu );
	const set = ( name ) => ( next ) =>
		onChange( { ...values, [ name ]: next } );
	const inputs = skill.inputs || {};

	if ( ! types.length ) {
		return (
			<p>
				{ __(
					'Aucun type de contenu ne peut être créé avec votre compte.',
					'upsidly-agent'
				) }
			</p>
		);
	}

	const changeType = ( slug ) =>
		onChange( {
			...values,
			type_contenu: slug,
			__terms: {},
			__status: 'draft',
		} );

	const taxonomies = ( type && type.taxonomies ) || [];
	const assignable = taxonomies.filter( ( taxonomy ) => taxonomy.canAssign );
	const terms = values.__terms || {};

	const statusOptions = [
		{ value: 'draft', label: __( 'Brouillon', 'upsidly-agent' ) },
		{
			value: 'pending',
			label: __( 'En attente de relecture', 'upsidly-agent' ),
		},
	];
	if ( type && type.canPublish ) {
		statusOptions.push( {
			value: 'publish',
			label: __( 'Publié directement', 'upsidly-agent' ),
		} );
	}

	return (
		<div className="upsidly-agent-fields">
			<SelectControl
				label={ __( 'Type de contenu *', 'upsidly-agent' ) }
				value={ values.type_contenu }
				onChange={ changeType }
				disabled={ disabled }
				__nextHasNoMarginBottom
			>
				{ byOrigin( types ).map( ( [ origin, items ] ) => (
					<optgroup key={ origin } label={ origin }>
						{ items.map( ( item ) => (
							<option key={ item.slug } value={ item.slug }>
								{ item.label }
							</option>
						) ) }
					</optgroup>
				) ) }
			</SelectControl>

			<TextareaControl
				label={ __( 'Votre demande *', 'upsidly-agent' ) }
				help={
					type && type.isProduct
						? __(
								'Décrivez le produit comme à un collègue : simple ou variable, attributs et variations, prix, référence, stock, description courte et longue (vos textes seront repris tels quels).',
								'upsidly-agent'
							)
						: __(
								'Sujet, angle, éléments à inclure, textes à reprendre…',
								'upsidly-agent'
							)
				}
				placeholder={
					type && type.isProduct
						? __(
								'Ex. : produit variable « T-shirt Lin », tailles S, M, L, coloris Blanc et Noir, 29 € (35 € en L), référence TSL. Description courte : …',
								'upsidly-agent'
							)
						: ''
				}
				value={ values.demande || '' }
				onChange={ set( 'demande' ) }
				maxLength={ ( inputs.demande && inputs.demande.max ) || 6000 }
				rows={ 6 }
				disabled={ disabled }
				__nextHasNoMarginBottom
			/>

			<TextControl
				label={ __( 'Mot-clé principal', 'upsidly-agent' ) }
				value={ values.mot_cle || '' }
				onChange={ set( 'mot_cle' ) }
				maxLength={ ( inputs.mot_cle && inputs.mot_cle.max ) || 120 }
				disabled={ disabled }
				__nextHasNoMarginBottom
			/>

			{ inputs.longueur && (
				<RangeControl
					label={ __(
						'Longueur du texte principal (mots)',
						'upsidly-agent'
					) }
					value={ Number( values.longueur ) }
					onChange={ ( next ) =>
						set( 'longueur' )( next === undefined ? 800 : next )
					}
					min={ inputs.longueur.min }
					max={ inputs.longueur.max }
					step={ 50 }
					disabled={ disabled }
					__nextHasNoMarginBottom
				/>
			) }

			<SelectControl
				label={ __( 'Statut à la création', 'upsidly-agent' ) }
				value={ values.__status || 'draft' }
				options={ statusOptions }
				onChange={ set( '__status' ) }
				disabled={ disabled }
				__nextHasNoMarginBottom
			/>

			{ assignable.length > 0 && (
				<div className="upsidly-agent-taxonomies">
					<p className="upsidly-agent-taxonomies__intro">
						{ __(
							'Cochez les termes à imposer ; l’IA proposera les autres parmi les termes existants.',
							'upsidly-agent'
						) }
					</p>
					{ byOrigin( assignable ).map( ( [ origin, items ] ) => (
						<Fragment key={ origin }>
							<h4 className="upsidly-agent-taxonomies__group">
								{ origin }
							</h4>
							<div className="upsidly-agent-taxonomies__grid">
								{ items.map( ( taxonomy ) => (
									<TaxonomyBox
										key={ taxonomy.slug }
										taxonomy={ taxonomy }
										selected={
											terms[ taxonomy.slug ] || []
										}
										onChange={ ( ids ) =>
											set( '__terms' )( {
												...terms,
												[ taxonomy.slug ]: ids,
											} )
										}
										disabled={ disabled }
									/>
								) ) }
							</div>
						</Fragment>
					) ) }
				</div>
			) }

			{ type && type.fields > 0 && (
				<p className="description">
					{ sprintf(
						/* translators: %d: nombre de champs personnalisés. */
						__(
							'%d champ(s) personnalisé(s) de ce type seront remplis par l’IA quand c’est possible.',
							'upsidly-agent'
						),
						type.fields
					) }
				</p>
			) }
		</div>
	);
}
