/**
 * Formulaire généré depuis le schéma « inputs » d'une skill.
 */
import { createElement, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	FormTokenField,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

/**
 * Libellés lisibles des champs.
 */
const LABELS = {
	sujet: __( 'Sujet de l’article', 'upsidly-agent' ),
	mot_cle: __( 'Mot-clé principal', 'upsidly-agent' ),
	cible: __( 'Lecteur visé', 'upsidly-agent' ),
	longueur: __( 'Longueur (mots)', 'upsidly-agent' ),
	consignes: __( 'Consignes particulières', 'upsidly-agent' ),
	brief: __( 'Que doit contenir la page ?', 'upsidly-agent' ),
	mode: __( 'Créer une page / Modifier une page existante', 'upsidly-agent' ),
};

/**
 * Libellés des options d'énumération.
 */
const OPTION_LABELS = {
	creer: __( 'Créer une page', 'upsidly-agent' ),
	modifier: __( 'Modifier une page existante', 'upsidly-agent' ),
};

/**
 * Champs remplis côté serveur, jamais affichés.
 */
const HIDDEN = {
	'rediger-article': [ 'categories_existantes' ],
	'creer-page': [ 'titre_actuel', 'contenu_actuel' ],
};

/**
 * Champ visible pour cette skill ?
 *
 * @param {string} skillId Skill.
 * @param {string} name    Champ.
 * @return {boolean} Visible.
 */
function isVisible( skillId, name ) {
	if ( name === 'categories_existantes' ) {
		return false;
	}
	return ! ( HIDDEN[ skillId ] || [] ).includes( name );
}

/**
 * Valeurs initiales (défauts du schéma).
 *
 * @param {Object} skill Skill.
 * @return {Object} Valeurs.
 */
export function initialValues( skill ) {
	const values = {};
	Object.entries( skill.inputs || {} ).forEach( ( [ name, def ] ) => {
		if ( ! isVisible( skill.id, name ) ) {
			return;
		}
		if ( def.default !== undefined ) {
			values[ name ] = def.default;
		} else if ( def.type === 'list' ) {
			values[ name ] = [];
		} else if ( def.type === 'int' ) {
			values[ name ] = def.min !== undefined ? def.min : 0;
		} else {
			values[ name ] = '';
		}
	} );
	return values;
}

/**
 * Libellé d'un champ.
 *
 * @param {string} name Champ.
 * @param {Object} def  Définition.
 * @return {string} Libellé.
 */
function labelFor( name, def ) {
	const label = LABELS[ name ] || def.label || name;
	return def.required ? label + ' *' : label;
}

function Field( { name, def, value, onChange, disabled } ) {
	const label = labelFor( name, def );

	switch ( def.type ) {
		case 'int':
			return (
				<RangeControl
					label={ label }
					value={ Number( value ) }
					onChange={ ( next ) =>
						onChange( next === undefined ? def.default : next )
					}
					min={ def.min }
					max={ def.max }
					step={ 50 }
					disabled={ disabled }
					__nextHasNoMarginBottom
				/>
			);

		case 'enum':
			return (
				<SelectControl
					label={ label }
					value={ value }
					options={ ( def.options || [] ).map( ( option ) => ( {
						value: option,
						label: OPTION_LABELS[ option ] || option,
					} ) ) }
					onChange={ onChange }
					disabled={ disabled }
					__nextHasNoMarginBottom
				/>
			);

		case 'list':
			return (
				<FormTokenField
					label={ label }
					value={ Array.isArray( value ) ? value : [] }
					onChange={ ( tokens ) =>
						onChange(
							tokens
								.map( ( token ) =>
									typeof token === 'string'
										? token
										: token.value
								)
								.slice( 0, def.max_items || 50 )
						)
					}
					disabled={ disabled }
					__nextHasNoMarginBottom
				/>
			);

		case 'text':
			return (
				<TextareaControl
					label={ label }
					value={ value || '' }
					onChange={ onChange }
					maxLength={ def.max }
					rows={ 8 }
					disabled={ disabled }
					__nextHasNoMarginBottom
				/>
			);

		default:
			if ( ( def.max || 0 ) > 300 ) {
				return (
					<TextareaControl
						label={ label }
						value={ value || '' }
						onChange={ onChange }
						maxLength={ def.max }
						rows={ 4 }
						disabled={ disabled }
						__nextHasNoMarginBottom
					/>
				);
			}
			return (
				<TextControl
					label={ label }
					value={ value || '' }
					onChange={ onChange }
					maxLength={ def.max }
					disabled={ disabled }
					__nextHasNoMarginBottom
				/>
			);
	}
}

function PageSelect( { pages, pageId, onChange, disabled } ) {
	const options = [
		{ value: '0', label: __( '— Choisir une page —', 'upsidly-agent' ) },
		...pages.map( ( page ) => {
			let label = page.title;
			if ( page.status !== 'publish' ) {
				label += ` (${ page.status })`;
			}
			if ( page.is_elementor ) {
				label = sprintf(
					/* translators: %s: titre de la page. */
					__(
						'%s — Construite avec Elementor, non prise en charge',
						'upsidly-agent'
					),
					label
				);
			}
			return {
				value: String( page.id ),
				label,
				disabled: page.is_elementor || page.can_edit === false,
			};
		} ),
	];

	return (
		<SelectControl
			label={ __( 'Page à modifier *', 'upsidly-agent' ) }
			value={ String( pageId || 0 ) }
			options={ options }
			onChange={ ( next ) => onChange( parseInt( next, 10 ) || 0 ) }
			disabled={ disabled }
			help={
				pages.length
					? undefined
					: __( 'Aucune page disponible.', 'upsidly-agent' )
			}
			__nextHasNoMarginBottom
		/>
	);
}

export default function SkillForm( {
	skill,
	values,
	onChange,
	pages,
	pageId,
	onPageChange,
	disabled,
} ) {
	const entries = Object.entries( skill.inputs || {} ).filter( ( [ name ] ) =>
		isVisible( skill.id, name )
	);
	const isModify = skill.id === 'creer-page' && values.mode === 'modifier';

	const set = ( name ) => ( next ) =>
		onChange( { ...values, [ name ]: next } );

	return (
		<div className="upsidly-agent-fields">
			{ entries.map( ( [ name, def ] ) => (
				<Fragment key={ name }>
					<Field
						name={ name }
						def={ def }
						value={ values[ name ] }
						onChange={ set( name ) }
						disabled={ disabled }
					/>
					{ name === 'mode' && isModify && (
						<PageSelect
							pages={ pages }
							pageId={ pageId }
							onChange={ onPageChange }
							disabled={ disabled }
						/>
					) }
				</Fragment>
			) ) }
		</div>
	);
}
