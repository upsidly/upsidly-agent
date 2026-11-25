/**
 * Application « Assistant ».
 */
import {
	createElement,
	Fragment,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { getQueryArg } from '@wordpress/url';
import { Button, Card, CardBody, Notice, Spinner } from '@wordpress/components';

import CreditsGauge from './components/CreditsGauge';
import SkillPicker from './components/SkillPicker';
import SkillForm, { initialValues } from './components/SkillForm';
import ResultCard from './components/ResultCard';
import PageDiff from './components/PageDiff';

const STEPS = [
	__( 'Analyse de la demande…', 'upsidly-agent' ),
	__( 'Rédaction en cours…', 'upsidly-agent' ),
	__( 'Mise en forme…', 'upsidly-agent' ),
];

/**
 * Message d'erreur lisible à partir d'une erreur apiFetch.
 *
 * @param {Object} error Erreur renvoyée par apiFetch.
 * @return {string} Message.
 */
function errorMessage( error ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}
	return __( 'Une erreur inattendue s’est produite.', 'upsidly-agent' );
}

/**
 * Crédits restants si le quota est atteint.
 *
 * @param {Object|null} credits Bloc credits.
 * @return {boolean} Vrai si épuisés.
 */
function isExhausted( credits ) {
	return !! credits && credits.limit > 0 && credits.used >= credits.limit;
}

export default function App( { config } ) {
	const caps = config.caps || {};
	const pages = config.pages || [];

	const [ status, setStatus ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ credits, setCredits ] = useState( null );
	const [ skillId, setSkillId ] = useState( null );
	const [ values, setValues ] = useState( {} );
	const [ pageId, setPageId ] = useState( 0 );
	const [ submitting, setSubmitting ] = useState( false );
	const [ step, setStep ] = useState( 0 );
	const [ result, setResult ] = useState( null );
	const [ runError, setRunError ] = useState( null );
	const [ diffId, setDiffId ] = useState( () => {
		const fromUrl = parseInt(
			getQueryArg( window.location.href, 'diff' ) || '0',
			10
		);
		return fromUrl > 0 ? fromUrl : 0;
	} );
	const sending = useRef( false );

	const loadStatus = useCallback( ( refresh = false ) => {
		setLoadError( null );
		apiFetch( {
			path: '/upsidly-agent/v1/status' + ( refresh ? '?refresh=1' : '' ),
		} )
			.then( ( data ) => {
				setStatus( data );
				setCredits( data.credits || null );
			} )
			.catch( ( error ) => setLoadError( errorMessage( error ) ) );
	}, [] );

	useEffect( () => {
		loadStatus();
	}, [ loadStatus ] );

	// Messages d'étape qui tournent pendant la génération.
	useEffect( () => {
		if ( ! submitting ) {
			return undefined;
		}
		setStep( 0 );
		const timer = setInterval(
			() => setStep( ( current ) => ( current + 1 ) % STEPS.length ),
			4000
		);
		return () => clearInterval( timer );
	}, [ submitting ] );

	const skills = useMemo( () => {
		const list = ( status && status.skills ) || [];
		return list.filter(
			( skill ) => skill.id !== 'creer-page' || caps.edit_pages
		);
	}, [ status, caps.edit_pages ] );

	const skill = skills.find( ( item ) => item.id === skillId ) || null;
	const exhausted = isExhausted( credits );

	const chooseSkill = ( id ) => {
		const chosen = skills.find( ( item ) => item.id === id );
		setSkillId( id );
		setValues( chosen ? initialValues( chosen ) : {} );
		setPageId( 0 );
		setResult( null );
		setRunError( null );
	};

	const reset = () => {
		setResult( null );
		setRunError( null );
		setPageId( 0 );
		setValues( skill ? initialValues( skill ) : {} );
	};

	const generate = () => {
		if ( sending.current || ! skill || exhausted ) {
			return;
		}
		sending.current = true;
		setSubmitting( true );
		setRunError( null );
		setResult( null );

		const data = { skill: skill.id, inputs: values };
		if ( skill.id === 'creer-page' && values.mode === 'modifier' ) {
			data.page_id = pageId;
		}

		apiFetch( { path: '/upsidly-agent/v1/run', method: 'POST', data } )
			.then( ( response ) => {
				setResult( response );
				if ( response.credits ) {
					setCredits( response.credits );
				}
			} )
			.catch( ( error ) => {
				setRunError( errorMessage( error ) );
				if ( error && error.data && error.data.credits ) {
					setCredits( error.data.credits );
				}
			} )
			.finally( () => {
				sending.current = false;
				setSubmitting( false );
			} );
	};

	if ( diffId ) {
		return (
			<PageDiff
				draftId={ diffId }
				canApply={ !! caps.publish_pages }
				onClose={ () => setDiffId( 0 ) }
			/>
		);
	}

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				<p>{ loadError }</p>
				{ config.settingsUrl && (
					<p>
						<a href={ config.settingsUrl }>
							{ __( 'Ouvrir les réglages', 'upsidly-agent' ) }
						</a>
					</p>
				) }
				<Button
					variant="secondary"
					onClick={ () => loadStatus( true ) }
				>
					{ __( 'Réessayer', 'upsidly-agent' ) }
				</Button>
			</Notice>
		);
	}

	if ( ! status ) {
		return (
			<div className="upsidly-agent-loading">
				<Spinner />
				{ __( 'Chargement…', 'upsidly-agent' ) }
			</div>
		);
	}

	return (
		<div className="upsidly-agent-app">
			<CreditsGauge credits={ credits } />

			<SkillPicker
				skills={ skills }
				selected={ skillId }
				onSelect={ chooseSkill }
				disabled={ submitting }
			/>

			{ skill && ! result && (
				<Card className="upsidly-agent-form">
					<CardBody>
						<SkillForm
							skill={ skill }
							values={ values }
							onChange={ setValues }
							pages={ pages }
							pageId={ pageId }
							onPageChange={ setPageId }
							disabled={ submitting }
						/>

						{ runError && (
							<Notice status="error" isDismissible={ false }>
								{ runError }
							</Notice>
						) }

						<div className="upsidly-agent-actions">
							<Button
								variant="primary"
								onClick={ generate }
								isBusy={ submitting }
								disabled={
									submitting ||
									exhausted ||
									( skill.id === 'creer-page' &&
										values.mode === 'modifier' &&
										! pageId )
								}
							>
								{ __( 'Générer', 'upsidly-agent' ) }
							</Button>
							{ submitting && (
								<span className="upsidly-agent-progress">
									<Spinner />
									{ STEPS[ step ] }
								</span>
							) }
						</div>
					</CardBody>
				</Card>
			) }

			{ result && (
				<Fragment>
					<ResultCard
						result={ result }
						skillId={ skill ? skill.id : '' }
						canApply={ !! caps.publish_pages }
						onShowDiff={ () => setDiffId( result.post_id ) }
					/>
					<div className="upsidly-agent-actions">
						<Button variant="secondary" onClick={ reset }>
							{ __( 'Nouvelle demande', 'upsidly-agent' ) }
						</Button>
					</div>
				</Fragment>
			) }
		</div>
	);
}
