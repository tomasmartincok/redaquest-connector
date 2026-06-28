/**
 * RedaQuest block-editor integration.
 *
 * 1) "RedaQuest" document panel — PREPARE: generate per-platform social drafts from the article,
 *    edit them, pick accounts, toggle the image. (The schedule button here still works standalone.)
 * 2) Featured Image panel button — generate a brand illustration and set it as the featured image.
 * 3) Pre-publish panel — when a social post is prepared, a checkbox "schedule to RedaQuest on the
 *    same date" rides along with the blog's own Schedule/Publish action (one confirmation does both).
 *
 * Shared state lives in a small @wordpress/data store so the document panel (prepare) and the
 * pre-publish panel (confirm) stay in sync. All RedaQuest calls go through the same-site redaquest/v2
 * proxy (the token stays server-side in PHP).
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel, PluginPrePublishPanel } from '@wordpress/edit-post';
import { useState, useEffect, createRoot, render as wpRender } from '@wordpress/element';
import { createReduxStore, register, useSelect, useDispatch, select as dataSelect, dispatch as dataDispatch, subscribe } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { rawHandler, createBlock } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Spinner, Button, ButtonGroup, Tooltip, Notice, TextareaControl, TextControl, SelectControl, CheckboxControl, ToggleControl, Modal } from '@wordpress/components';

// Inline lucide icons (no emoji in product chrome — brand guideline).
const IconImage = ( props ) => (
	<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={ { verticalAlign: 'text-bottom', marginRight: 5 } } { ...props }>
		<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
		<circle cx="9" cy="9" r="2"></circle>
		<path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>
	</svg>
);
const IconPencil = ( props ) => (
	<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={ { verticalAlign: 'text-bottom', marginRight: 6 } } { ...props }>
		<path d="M12 20h9"></path>
		<path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
	</svg>
);

// Image "look" (rendering) — the axis a user picks to override the brand manual default (e.g. flat → photo).
const IMAGE_TYPE_OPTIONS = [
	{ value: '', label: __( 'From brand manual', 'redaquest-connector' ) },
	{ value: 'photo', label: __( 'Photographic / realistic', 'redaquest-connector' ) },
	{ value: 'flat-design', label: __( 'Flat design', 'redaquest-connector' ) },
	{ value: '3d-render', label: __( '3D render', 'redaquest-connector' ) },
	{ value: 'illustration', label: __( 'Illustration', 'redaquest-connector' ) },
];

// Aesthetic mood preset.
const IMAGE_STYLE_OPTIONS = [
	{ value: '', label: __( 'From brand manual', 'redaquest-connector' ) },
	{ value: 'modern', label: __( 'Modern', 'redaquest-connector' ) },
	{ value: 'minimalist', label: __( 'Minimalist', 'redaquest-connector' ) },
	{ value: 'playful', label: __( 'Playful', 'redaquest-connector' ) },
	{ value: 'elegant', label: __( 'Elegant', 'redaquest-connector' ) },
	{ value: 'corporate', label: __( 'Corporate', 'redaquest-connector' ) },
];

const PLATFORM_LABELS = {
	facebook: 'Facebook',
	instagram: 'Instagram',
	linkedin: 'LinkedIn',
	twitter: 'Twitter/X',
	threads: 'Threads',
	youtube: 'YouTube',
	pinterest: 'Pinterest',
	googlebusiness: 'Google Business',
};

function stripHtml( html ) {
	const tmp = document.createElement( 'div' );
	tmp.innerHTML = html || '';
	return ( tmp.textContent || tmp.innerText || '' ).trim();
}

function computeScheduleWhen( articleDate ) {
	const d = articleDate ? new Date( articleDate ) : null;
	if ( d && ! isNaN( d.getTime() ) && d.getTime() > Date.now() + 60000 ) {
		return d;
	}
	return new Date( Date.now() + 5 * 60 * 1000 );
}

/* ------------------------------------------------------------------ */
/* Shared store: prepare (document panel) <-> confirm (pre-publish)    */
/* ------------------------------------------------------------------ */

const STORE = 'redaquest/social';

const DEFAULT_STATE = {
	status: null,        // { connected, appUrl, connectUrl, ... }
	loaded: false,
	loadError: null,
	accounts: [],
	drafts: null,        // { content: {platform:text}, firstComments: {platform:text} }
	selected: {},        // { accountId: bool }
	useImage: true,
	alsoSchedule: true,  // pre-publish checkbox
	scheduled: false,    // already pushed to RedaQuest this session
	blogOpen: false,     // Blog Writer sheet open? (triggered from the header/sidebar launcher)
	blogBusy: false,     // a generation is running (so the header button keeps spinning when closed)
};

const store = createReduxStore( STORE, {
	reducer( state = DEFAULT_STATE, action ) {
		switch ( action.type ) {
			case 'SET_STATUS': return { ...state, status: action.value };
			case 'SET_LOADED': return { ...state, loaded: action.value };
			case 'SET_ERROR': return { ...state, loadError: action.value };
			case 'SET_ACCOUNTS': return { ...state, accounts: action.value };
			case 'SET_DRAFTS': return { ...state, drafts: action.value };
			case 'SET_SELECTED': return { ...state, selected: action.value };
			case 'SET_USE_IMAGE': return { ...state, useImage: action.value };
			case 'SET_ALSO_SCHEDULE': return { ...state, alsoSchedule: action.value };
			case 'SET_SCHEDULED': return { ...state, scheduled: action.value };
			case 'SET_BLOG_OPEN': return { ...state, blogOpen: action.value };
			case 'SET_BLOG_BUSY': return { ...state, blogBusy: action.value };
			default: return state;
		}
	},
	actions: {
		setStatus: ( value ) => ( { type: 'SET_STATUS', value } ),
		setLoaded: ( value ) => ( { type: 'SET_LOADED', value } ),
		setError: ( value ) => ( { type: 'SET_ERROR', value } ),
		setAccounts: ( value ) => ( { type: 'SET_ACCOUNTS', value } ),
		setDrafts: ( value ) => ( { type: 'SET_DRAFTS', value } ),
		setSelected: ( value ) => ( { type: 'SET_SELECTED', value } ),
		setUseImage: ( value ) => ( { type: 'SET_USE_IMAGE', value } ),
		setAlsoSchedule: ( value ) => ( { type: 'SET_ALSO_SCHEDULE', value } ),
		setScheduled: ( value ) => ( { type: 'SET_SCHEDULED', value } ),
		setBlogOpen: ( value ) => ( { type: 'SET_BLOG_OPEN', value } ),
		setBlogBusy: ( value ) => ( { type: 'SET_BLOG_BUSY', value } ),
	},
	selectors: {
		getStatus: ( s ) => s.status,
		isLoaded: ( s ) => s.loaded,
		getError: ( s ) => s.loadError,
		getAccounts: ( s ) => s.accounts,
		getDrafts: ( s ) => s.drafts,
		getSelected: ( s ) => s.selected,
		getUseImage: ( s ) => s.useImage,
		getAlsoSchedule: ( s ) => s.alsoSchedule,
		isScheduled: ( s ) => s.scheduled,
		isBlogOpen: ( s ) => s.blogOpen,
		isBlogBusy: ( s ) => s.blogBusy,
		isPrepared: ( s ) =>
			!! ( s.drafts && Object.keys( s.drafts.content || {} ).length > 0 &&
				Object.values( s.selected ).some( Boolean ) ),
	},
} );
register( store );

// One-time load of connection status + accounts, shared by both panels.
let initStarted = false;
function ensureLoaded() {
	if ( initStarted ) return;
	initStarted = true;
	const rq = dataDispatch( STORE );
	apiFetch( { path: '/redaquest/v2/status' } )
		.then( ( s ) => {
			rq.setStatus( s );
			if ( s && s.connected ) {
				return apiFetch( { path: '/redaquest/v2/accounts' } ).then( ( r ) => rq.setAccounts( ( r && r.accounts ) || [] ) );
			}
			return null;
		} )
		.catch( ( e ) => rq.setError( ( e && e.message ) || __( 'Could not load status.', 'redaquest-connector' ) ) )
		.finally( () => rq.setLoaded( true ) );
}

// Shared schedule routine — used by the document panel button AND the pre-publish auto-ride-along.
async function scheduleToRedaquest( { drafts, accounts, selected, useImage, date, title, permalink, postId, featuredId, appUrl } ) {
	const notices = dataDispatch( 'core/notices' );
	if ( ! drafts ) return false;
	const chosen = accounts.filter( ( a ) => selected[ a.id ] );
	if ( chosen.length === 0 ) {
		notices.createErrorNotice( __( 'Select at least one account in the RedaQuest tab.', 'redaquest-connector' ), { id: 'redaquest-schedule', type: 'snackbar' } );
		return false;
	}

	const when = computeScheduleWhen( date );
	const platforms = Array.from( new Set( chosen.map( ( a ) => a.platform ) ) );
	const selectedAccountIds = {};
	chosen.forEach( ( a ) => {
		if ( ! selectedAccountIds[ a.platform ] ) selectedAccountIds[ a.platform ] = [];
		selectedAccountIds[ a.platform ].push( a.id );
	} );

	const contentMap = {};
	const firstComments = {};
	platforms.forEach( ( p ) => {
		if ( drafts.content[ p ] ) contentMap[ p ] = drafts.content[ p ];
		if ( drafts.firstComments && drafts.firstComments[ p ] ) firstComments[ p ] = drafts.firstComments[ p ];
	} );

	try {
		const res = await apiFetch( {
			path: '/redaquest/v2/schedule',
			method: 'POST',
			data: {
				title: title || 'WordPress',
				content: contentMap,
				firstComments,
				platforms,
				selectedAccountIds,
				scheduledDate: when.toISOString(),
				sourceUrl: permalink,
				sourcePostId: postId,
				useFeaturedImage: !! ( useImage && featuredId ),
			},
		} );
		const postUrl = appUrl && res && res.postId ? `${ appUrl }/social/posts?post=${ res.postId }` : null;
		const actions = postUrl ? [ { label: __( 'Open in RedaQuest', 'redaquest-connector' ), onClick: () => window.open( postUrl, '_blank', 'noreferrer' ) } ] : [];
		if ( res && res.alreadyScheduled ) {
			notices.createSuccessNotice( __( 'This article is already scheduled in RedaQuest.', 'redaquest-connector' ), { id: 'redaquest-schedule', actions } );
		} else {
			const count = res && res.scheduledCount ? ` (${ res.scheduledCount }×)` : '';
			notices.createSuccessNotice( __( 'Post scheduled to RedaQuest', 'redaquest-connector' ) + count, { id: 'redaquest-schedule', actions } );
		}
		return true;
	} catch ( e ) {
		notices.createErrorNotice( ( e && e.message ) || __( 'Scheduling failed.', 'redaquest-connector' ), { id: 'redaquest-schedule' } );
		return false;
	}
}

// Auto ride-along: after the blog itself is scheduled/published (first time only), push to RedaQuest.
// `dateOverride` is the publish date captured at save-START (the edited value can be cleared post-save).
function maybeAlsoScheduleRedaquest( dateOverride ) {
	const s = dataSelect( STORE );
	if ( ! s.getAlsoSchedule() || ! s.isPrepared() || s.isScheduled() ) return;
	// Optimistic guard against double-fire; reset on failure.
	dataDispatch( STORE ).setScheduled( true );

	const ed = dataSelect( 'core/editor' );
	const status = s.getStatus();
	scheduleToRedaquest( {
		drafts: s.getDrafts(),
		accounts: s.getAccounts(),
		selected: s.getSelected(),
		useImage: s.getUseImage(),
		date: dateOverride || ed.getEditedPostAttribute( 'date' ),
		title: ed.getEditedPostAttribute( 'title' ),
		permalink: ed.getPermalink ? ed.getPermalink() : ( ( ed.getCurrentPost() || {} ).link || '' ),
		postId: ed.getCurrentPostId(),
		featuredId: ed.getEditedPostAttribute( 'featured_media' ),
		appUrl: status && status.appUrl,
	} ).then( ( ok ) => { if ( ! ok ) dataDispatch( STORE ).setScheduled( false ); } );
}

// Detect the blog's own Schedule/Publish completing and fire the ride-along once. We watch the
// PERSISTED status (getCurrentPost, updates only after a save lands) tracked across non-saving ticks,
// so the optimistic edited status can't make us miss it; and we snapshot the user's intended date at
// save-START, before the edited date is cleared once the save settles.
let wasSavingPost = false;
let persistedStatus = null;        // getCurrentPost().status while NOT saving = true pre-save state
let prevStatusAtSaveStart = null;
let dateAtSaveStart = null;
subscribe( () => {
	const ed = dataSelect( 'core/editor' );
	if ( ! ed || ! ed.getCurrentPostId ) return;
	const saving = ed.isSavingPost() && ! ed.isAutosavingPost();

	if ( saving && ! wasSavingPost ) {
		prevStatusAtSaveStart = persistedStatus;
		dateAtSaveStart = ed.getEditedPostAttribute( 'date' );
	}

	if ( ! saving && wasSavingPost ) {
		const failed = ed.didPostSaveRequestFail && ed.didPostSaveRequestFail();
		const cur = ( ed.getCurrentPost() || {} ).status;
		const wasUnpublished = prevStatusAtSaveStart == null || prevStatusAtSaveStart === 'draft' || prevStatusAtSaveStart === 'auto-draft' || prevStatusAtSaveStart === 'pending';
		if ( ! failed && wasUnpublished && ( cur === 'future' || cur === 'publish' ) ) {
			maybeAlsoScheduleRedaquest( dateAtSaveStart );
		}
	}

	if ( ! saving ) persistedStatus = ( ed.getCurrentPost() || {} ).status;
	wasSavingPost = saving;
} );

/* ------------------------------------------------------------------ */
/* Featured Image panel: "Generate image" (RedaQuest)                 */
/* ------------------------------------------------------------------ */

function FeaturedGenerateButton() {
	const [ busy, setBusy ] = useState( false );
	const [ hint, setHint ] = useState( '' );
	const [ imgType, setImgType ] = useState( '' );
	const [ imgStyle, setImgStyle ] = useState( '' );
	const { editPost } = useDispatch( 'core/editor' );
	const { createSuccessNotice, createErrorNotice } = useDispatch( 'core/notices' );
	const { title, content, postId } = useSelect( ( select ) => {
		const ed = select( 'core/editor' );
		return {
			title: ed.getEditedPostAttribute( 'title' ),
			content: ed.getEditedPostContent(),
			postId: ed.getCurrentPostId(),
		};
	}, [] );

	const onClick = async () => {
		setBusy( true );
		try {
			const res = await apiFetch( {
				path: '/redaquest/v2/generate-image',
				method: 'POST',
				data: {
					article: { title, body: stripHtml( content ) },
					postId,
					instruction: hint.trim() || undefined,
					type: imgType || undefined,
					style: imgStyle || undefined,
				},
			} );
			if ( res && res.featuredMediaId ) {
				editPost( { featured_media: res.featuredMediaId } );
				createSuccessNotice( __( 'Featured image generated and set.', 'redaquest-connector' ), { id: 'redaquest-image', type: 'snackbar' } );
			} else if ( res && res.imageUrl ) {
				createSuccessNotice( __( 'Image generated. Save the article and try again.', 'redaquest-connector' ), { id: 'redaquest-image', type: 'snackbar' } );
			}
		} catch ( e ) {
			createErrorNotice( ( e && e.message ) || __( 'Image generation failed.', 'redaquest-connector' ), { id: 'redaquest-image' } );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div style={ { marginTop: 8 } }>
			<TextareaControl
				label={ __( 'What should the image show? (optional)', 'redaquest-connector' ) }
				help={ __( 'Leave empty to derive it from the article. Any language works.', 'redaquest-connector' ) }
				value={ hint }
				onChange={ setHint }
				rows={ 2 }
				disabled={ busy }
			/>
			<SelectControl
				label={ __( 'Image type', 'redaquest-connector' ) }
				value={ imgType }
				options={ IMAGE_TYPE_OPTIONS }
				onChange={ setImgType }
				disabled={ busy }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Style (mood)', 'redaquest-connector' ) }
				value={ imgStyle }
				options={ IMAGE_STYLE_OPTIONS }
				onChange={ setImgStyle }
				disabled={ busy }
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				onClick={ onClick }
				isBusy={ busy }
				disabled={ busy || ! title }
				style={ { width: '100%', justifyContent: 'center', height: 'auto', minHeight: 36, whiteSpace: 'normal', textAlign: 'center', marginTop: 8 } }
			>
				{ busy ? __( 'Generating image…', 'redaquest-connector' ) : __( 'Generate image (RedaQuest)', 'redaquest-connector' ) }
			</Button>
		</div>
	);
}

addFilter( 'editor.PostFeaturedImage', 'redaquest/featured-generate', ( OriginalComponent ) => {
	return ( props ) => (
		<>
			<OriginalComponent { ...props } />
			<FeaturedGenerateButton />
		</>
	);
} );

/* ------------------------------------------------------------------ */
/* RedaQuest document panel: PREPARE (generate + edit + pick accounts) */
/* ------------------------------------------------------------------ */

function RedaQuestPanel() {
	const [ generating, setGenerating ] = useState( false );
	const [ schedulingNow, setSchedulingNow ] = useState( false );
	const { createErrorNotice } = useDispatch( 'core/notices' );
	const rq = useDispatch( STORE );

	const { status, accounts, loaded, loadError, drafts, selected, useImage, scheduled } = useSelect( ( select ) => {
		const s = select( STORE );
		return {
			status: s.getStatus(),
			accounts: s.getAccounts(),
			loaded: s.isLoaded(),
			loadError: s.getError(),
			drafts: s.getDrafts(),
			selected: s.getSelected(),
			useImage: s.getUseImage(),
			scheduled: s.isScheduled(),
		};
	}, [] );

	const { title, content, permalink, date, postId, featuredId } = useSelect( ( select ) => {
		const ed = select( 'core/editor' );
		return {
			title: ed.getEditedPostAttribute( 'title' ),
			content: ed.getEditedPostContent(),
			permalink: ed.getPermalink ? ed.getPermalink() : ( ( ed.getCurrentPost() || {} ).link || '' ),
			date: ed.getEditedPostAttribute( 'date' ),
			postId: ed.getCurrentPostId(),
			featuredId: ed.getEditedPostAttribute( 'featured_media' ),
		};
	}, [] );

	const featuredMedia = useSelect(
		( select ) => ( featuredId ? select( 'core' ).getMedia( featuredId ) : null ),
		[ featuredId ]
	);
	const featuredUrl = featuredMedia && featuredMedia.source_url ? featuredMedia.source_url : null;

	useEffect( () => { ensureLoaded(); }, [] );

	const platformsWithAccounts = Array.from( new Set( accounts.map( ( a ) => a.platform ) ) );

	const onGenerate = async () => {
		setGenerating( true );
		rq.setScheduled( false );
		try {
			const res = await apiFetch( {
				path: '/redaquest/v2/generate',
				method: 'POST',
				data: { article: { title, body: stripHtml( content ), url: permalink }, platforms: platformsWithAccounts },
			} );
			rq.setDrafts( { content: ( res && res.content ) || {}, firstComments: ( res && res.firstComments ) || {} } );
			// Pre-select all accounts so the post is "prepared" by default; user can uncheck.
			const all = {};
			accounts.forEach( ( a ) => { all[ a.id ] = true; } );
			rq.setSelected( all );
		} catch ( e ) {
			createErrorNotice( ( e && e.message ) || __( 'Generation failed.', 'redaquest-connector' ), { id: 'redaquest-generate' } );
		} finally {
			setGenerating( false );
		}
	};

	const updateDraft = ( platform, value ) => {
		rq.setDrafts( { ...drafts, content: { ...drafts.content, [ platform ]: value } } );
	};

	const onSchedule = async () => {
		setSchedulingNow( true );
		const ok = await scheduleToRedaquest( {
			drafts, accounts, selected, useImage, date, title, permalink, postId, featuredId,
			appUrl: status && status.appUrl,
		} );
		if ( ok ) rq.setScheduled( true );
		setSchedulingNow( false );
	};

	return (
		<PluginDocumentSettingPanel name="redaquest" title="RedaQuest" className="redaquest-panel">
			{ ! loaded && <Spinner /> }
			{ loaded && loadError && (
				<Notice status="error" isDismissible={ false }>{ loadError }</Notice>
			) }

			{ loaded && status && ! status.connected && (
				<div>
					<p>{ __( 'This site is not connected to RedaQuest.', 'redaquest-connector' ) }</p>
					<Button variant="primary" href={ status.connectUrl }>{ __( 'Connect RedaQuest', 'redaquest-connector' ) }</Button>
				</div>
			) }

			{ loaded && status && status.connected && (
				<div>
					{ accounts.length === 0 && (
						<Notice status="warning" isDismissible={ false }>{ __( 'No connected social accounts in this workspace.', 'redaquest-connector' ) }</Notice>
					) }

					<Button variant="secondary" onClick={ onGenerate } isBusy={ generating } disabled={ generating || ! title }>
						{ generating ? __( 'Generating…', 'redaquest-connector' ) : __( 'Prepare post from article', 'redaquest-connector' ) }
					</Button>
					{ generating && (
						<p style={ { fontSize: 12, color: '#555', marginTop: 6 } }>{ __( 'Preparing texts, this takes a moment…', 'redaquest-connector' ) }</p>
					) }

					{ drafts && (
						<div style={ { marginTop: 12 } }>
							{ Object.keys( drafts.content ).map( ( p ) => (
								<TextareaControl key={ p } label={ PLATFORM_LABELS[ p ] || p } value={ drafts.content[ p ] } onChange={ ( v ) => updateDraft( p, v ) } rows={ 5 } />
							) ) }
							<p style={ { fontSize: 11, color: '#777', marginTop: 0 } }>{ __( 'The article link is added to the first comment automatically.', 'redaquest-connector' ) }</p>

							{ featuredId ? (
								<div style={ { margin: '8px 0' } }>
									<CheckboxControl label={ __( 'Use the article’s featured image', 'redaquest-connector' ) } checked={ useImage } onChange={ rq.setUseImage } />
									{ useImage && featuredUrl && (
										<img src={ featuredUrl } alt="" style={ { maxWidth: '100%', borderRadius: 4, marginTop: 4, display: 'block' } } />
									) }
								</div>
							) : (
								<p style={ { fontSize: 11, color: '#999', margin: '8px 0' } }>{ __( 'No image. Generate one in the “Featured image” panel.', 'redaquest-connector' ) }</p>
							) }

							<p style={ { fontWeight: 600, marginBottom: 4 } }>{ __( 'Accounts', 'redaquest-connector' ) }</p>
							{ accounts.map( ( a ) => (
								<CheckboxControl
									key={ a.id }
									label={ `${ PLATFORM_LABELS[ a.platform ] || a.platform } · ${ a.displayName || a.username || a.name || a.id }` }
									checked={ !! selected[ a.id ] }
									onChange={ ( c ) => rq.setSelected( { ...selected, [ a.id ]: c } ) }
								/>
							) ) }

							<p style={ { fontSize: 12, color: '#555', margin: '10px 0 4px' } }>
								{ __( 'Will be scheduled for the article date:', 'redaquest-connector' ) }{ ' ' }
								<strong>{ computeScheduleWhen( date ).toLocaleString() }</strong>
							</p>

							<div style={ { marginTop: 8 } }>
								<Button variant="primary" onClick={ onSchedule } isBusy={ schedulingNow } disabled={ schedulingNow || scheduled }>
									{ schedulingNow ? __( 'Scheduling…', 'redaquest-connector' ) : scheduled ? __( 'Scheduled ✓', 'redaquest-connector' ) : __( 'Schedule to RedaQuest', 'redaquest-connector' ) }
								</Button>
								{ ! scheduled && (
									<p style={ { fontSize: 11, color: '#777', marginTop: 6 } }>{ __( 'Tip: or tick “Also schedule to RedaQuest” when you publish the article.', 'redaquest-connector' ) }</p>
								) }
							</div>
						</div>
					) }
				</div>
			) }
		</PluginDocumentSettingPanel>
	);
}

/* ------------------------------------------------------------------ */
/* Pre-publish panel: CONFIRM (ride-along checkbox)                   */
/* ------------------------------------------------------------------ */

function RedaQuestPrePublish() {
	useEffect( () => { ensureLoaded(); }, [] );
	const rq = useDispatch( STORE );
	const { status, prepared, scheduled, alsoSchedule, accounts, selected } = useSelect( ( select ) => {
		const s = select( STORE );
		return {
			status: s.getStatus(),
			prepared: s.isPrepared(),
			scheduled: s.isScheduled(),
			alsoSchedule: s.getAlsoSchedule(),
			accounts: s.getAccounts(),
			selected: s.getSelected(),
		};
	}, [] );

	if ( ! status || ! status.connected ) return null;

	const chosen = accounts.filter( ( a ) => selected[ a.id ] );
	const platforms = Array.from( new Set( chosen.map( ( a ) => PLATFORM_LABELS[ a.platform ] || a.platform ) ) );

	return (
		<PluginPrePublishPanel title="RedaQuest" initialOpen={ true }>
			{ scheduled ? (
				<p style={ { margin: 0 } }>{ __( '✓ Also scheduled to social media.', 'redaquest-connector' ) }</p>
			) : prepared ? (
				<div>
					<CheckboxControl
						label={ __( 'Also schedule to RedaQuest (same date)', 'redaquest-connector' ) }
						checked={ alsoSchedule }
						onChange={ rq.setAlsoSchedule }
					/>
					<p style={ { fontSize: 12, color: '#555', margin: '4px 0 0' } }>{ platforms.join( ', ' ) } · { chosen.length }{ ' ' }{ __( 'accounts', 'redaquest-connector' ) }</p>
				</div>
			) : (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Prepare the post in the RedaQuest tab on the right. Generate the texts and pick accounts.', 'redaquest-connector' ) }
				</Notice>
			) }
		</PluginPrePublishPanel>
	);
}

/* ------------------------------------------------------------------ */
/* RedaQuest Blog Writer: topic -> outline (brake) -> full GEO article  */
/* ------------------------------------------------------------------ */

// Serialize the (edited) structured outline into clean plain text the writer follows — NO markdown
// symbols, so the editor UI shows real fields, not '##'/'**'.
function serializeOutline( o ) {
	if ( ! o ) return '';
	const L = [];
	if ( o.title ) L.push( `Titulok (H1): ${ o.title }` );
	if ( o.angle ) L.push( `Uhol: ${ o.angle }` );
	if ( o.answerParagraph ) L.push( `Úvodná odpoveď: ${ o.answerParagraph }` );
	if ( Array.isArray( o.sections ) && o.sections.length ) {
		L.push( '' );
		L.push( 'Sekcie:' );
		o.sections.forEach( ( s, i ) => {
			L.push( `${ i + 1 }. ${ s.h2 || '' }${ s.brief ? ` (${ s.brief })` : '' }` );
			if ( Array.isArray( s.h3 ) ) s.h3.forEach( ( h ) => L.push( `   - ${ h }` ) );
		} );
	}
	if ( Array.isArray( o.faqPlan ) && o.faqPlan.length ) {
		L.push( '' );
		L.push( 'FAQ otázky:' );
		o.faqPlan.forEach( ( q, i ) => L.push( `${ i + 1 }. ${ q }` ) );
	}
	if ( o.metaTitle ) L.push( `\nMeta title: ${ o.metaTitle }` );
	if ( o.metaDescription ) L.push( `Meta description: ${ o.metaDescription }` );
	return L.join( '\n' );
}

function RedaQuestBlogModal() {
	useEffect( () => { ensureLoaded(); }, [] );
	const status = useSelect( ( select ) => select( STORE ).getStatus(), [] );
	const open = useSelect( ( select ) => select( STORE ).isBlogOpen(), [] );
	const { setBlogOpen, setBlogBusy } = useDispatch( STORE );
	const { createSuccessNotice, createErrorNotice, createWarningNotice } = useDispatch( 'core/notices' );
	const { insertBlocks } = useDispatch( 'core/block-editor' );
	const { editPost } = useDispatch( 'core/editor' );
	const { postId, currentTitle } = useSelect( ( select ) => {
		const ed = select( 'core/editor' );
		return { postId: ed.getCurrentPostId(), currentTitle: ed.getEditedPostAttribute( 'title' ) };
	}, [] );

	const [ step, setStep ] = useState( 1 );      // 1 topic · 2 audience · 3 outline
	const [ topic, setTopic ] = useState( '' );
	const [ thesis, setThesis ] = useState( '' );
	const [ example, setExample ] = useState( '' );
	const [ audience, setAudience ] = useState( '' ); // composed from the picked persona (no visible field)
	const [ keywords, setKeywords ] = useState( '' );
	const [ sourceUrls, setSourceUrls ] = useState( '' );
	const [ sourceContent, setSourceContent ] = useState( '' ); // pasted/uploaded existing material
	const [ showSource, setShowSource ] = useState( false );
	const [ webResearch, setWebResearch ] = useState( true );
	const [ genImage, setGenImage ] = useState( true );       // step 4: also generate a cover illustration
	const [ imageStyle, setImageStyle ] = useState( 'brand' ); // 'brand' | 'photo'
	const [ length, setLength ] = useState( 'spoke' );
	const [ outline, setOutline ] = useState( null );
	const [ research, setResearch ] = useState( { context: '', sources: [] } );
	const [ info, setInfo ] = useState( null );
	const [ busyOutline, setBusyOutline ] = useState( false );
	const [ busyDraft, setBusyDraft ] = useState( false );
	// personas: null = not loaded, [] = none
	const [ personas, setPersonas ] = useState( null );
	const [ personasInfo, setPersonasInfo ] = useState( { hasManual: false, brandName: '' } );
	const [ loadingPersonas, setLoadingPersonas ] = useState( false );
	const [ selectedPersonaId, setSelectedPersonaId ] = useState( '' );
	const [ credits, setCredits ] = useState( { remaining: null, costs: null } ); // balance + per-action costs

	const connected = !! ( status && status.connected );

	// Credit feedback. The edge returns creditsUsed/creditsRemaining on success and
	// { code:'INSUFFICIENT_CREDITS', available } (HTTP 402) when the owner is out of credits.
	const creditSuffix = ( res ) => {
		const u = res && typeof res.creditsUsed === 'number' ? res.creditsUsed : null;
		const r = res && typeof res.creditsRemaining === 'number' ? res.creditsRemaining : null;
		if ( ! u ) return '';
		return r === null
			? ` · ${ u } ${ __( 'credits used', 'redaquest-connector' ) }`
			: ` · ${ u } ${ __( 'credits used', 'redaquest-connector' ) } · ${ r } ${ __( 'left', 'redaquest-connector' ) }`;
	};
	const creditError = ( e ) => {
		if ( e && e.code === 'INSUFFICIENT_CREDITS' ) {
			const have = typeof e.available === 'number' ? ` (${ e.available } ${ __( 'left', 'redaquest-connector' ) })` : '';
			return __( 'Not enough credits', 'redaquest-connector' ) + have + '. ' + __( 'Top up in RedaQuest to continue.', 'redaquest-connector' );
		}
		return null;
	};

	const buildInputs = () => {
		const angle = {};
		if ( thesis.trim() ) angle.thesis = thesis.trim();
		if ( example.trim() ) angle.example = example.trim();
		if ( audience.trim() ) angle.audience = audience.trim();
		const kw = keywords.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
		const urls = sourceUrls.split( /[\n,]/ ).map( ( s ) => s.trim() ).filter( Boolean );
		return {
			topic: topic.trim() || currentTitle,
			angle: Object.keys( angle ).length ? angle : undefined,
			keywords: kw.length ? kw : undefined,
			length,
			sourceUrls: urls.length ? urls : undefined,
			sourceContent: sourceContent.trim() ? sourceContent.trim().slice( 0, 60000 ) : undefined,
		};
	};

	// Read an uploaded text-based doc (.md/.txt/.html) into the source field. Word/Google Docs: paste.
	const onSourceFile = ( e ) => {
		const file = e.target.files && e.target.files[ 0 ];
		if ( ! file ) return;
		const reader = new FileReader();
		reader.onload = () => {
			let text = String( reader.result || '' );
			if ( /\.html?$/i.test( file.name ) ) {
				const tmp = document.createElement( 'div' );
				tmp.innerHTML = text;
				text = tmp.textContent || tmp.innerText || '';
			}
			setSourceContent( ( prev ) => ( prev ? prev + '\n\n' : '' ) + text.slice( 0, 60000 ) );
			setShowSource( true );
		};
		reader.readAsText( file );
		e.target.value = '';
	};

	const patchOutline = ( field, value ) => setOutline( ( prev ) => ( { ...( prev || {} ), [ field ]: value } ) );
	const patchSection = ( idx, field, value ) => setOutline( ( prev ) => {
		const sections = [ ...( prev.sections || [] ) ];
		sections[ idx ] = { ...sections[ idx ], [ field ]: value };
		return { ...prev, sections };
	} );
	const addSection = () => setOutline( ( prev ) => ( { ...prev, sections: [ ...( prev.sections || [] ), { h2: '', brief: '' } ] } ) );
	const removeSection = ( idx ) => setOutline( ( prev ) => ( { ...prev, sections: ( prev.sections || [] ).filter( ( _, i ) => i !== idx ) } ) );
	const patchFaq = ( idx, value ) => setOutline( ( prev ) => {
		const faqPlan = [ ...( prev.faqPlan || [] ) ];
		faqPlan[ idx ] = value;
		return { ...prev, faqPlan };
	} );
	const addFaq = () => setOutline( ( prev ) => ( { ...prev, faqPlan: [ ...( prev.faqPlan || [] ), '' ] } ) );
	const removeFaq = ( idx ) => setOutline( ( prev ) => ( { ...prev, faqPlan: ( prev.faqPlan || [] ).filter( ( _, i ) => i !== idx ) } ) );

	const loadPersonas = async () => {
		if ( personas !== null || loadingPersonas ) return;
		setLoadingPersonas( true );
		try {
			const res = await apiFetch( { path: '/redaquest/v2/brand/personas' } );
			setPersonas( Array.isArray( res.personas ) ? res.personas : [] );
			setPersonasInfo( { hasManual: !! res.hasManual, brandName: res.brandName || '' } );
			setCredits( { remaining: typeof res.creditsRemaining === 'number' ? res.creditsRemaining : null, costs: res.costs || null } );
		} catch ( e ) {
			setPersonas( [] );
		} finally {
			setLoadingPersonas( false );
		}
	};

	const selectPersona = ( p ) => {
		setSelectedPersonaId( p.id );
		setAudience( [ p.name, p.age ? `(${ p.age })` : '', p.profile, p.situation ].filter( Boolean ).join( ' ' ).trim() );
	};

	const goToAudience = () => {
		if ( ! ( topic.trim() || currentTitle ) ) {
			createErrorNotice( __( 'Add a topic or a post title first.', 'redaquest-connector' ), { id: 'rq-blog', type: 'snackbar' } );
			return;
		}
		setStep( 2 );
		loadPersonas();
	};

	// Preload personas (and the workspace/brand name) as soon as the sheet opens.
	useEffect( () => {
		if ( open && connected ) loadPersonas();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open, connected ] );

	const onOutline = async () => {
		const base = buildInputs();
		if ( ! base.topic ) {
			createErrorNotice( __( 'Add a topic or a post title first.', 'redaquest-connector' ), { id: 'rq-blog', type: 'snackbar' } );
			return;
		}
		setBusyOutline( true );
		setBlogBusy( true );
		setStep( 3 );
		try {
			const res = await apiFetch( { path: '/redaquest/v2/blog/outline', method: 'POST', data: { ...base, webResearch } } );
			setOutline( res.outline || {} );
			setResearch( { context: res.researchContext || '', sources: res.sources || [] } );
			setInfo( { brandManualUsed: !! res.brandManualUsed, brandName: res.brandName || '', modelUsed: res.modelUsed || '' } );
			createSuccessNotice( __( 'Outline ready.', 'redaquest-connector' ) + creditSuffix( res ), { id: 'rq-blog', type: 'snackbar' } );
		} catch ( e ) {
			createErrorNotice( creditError( e ) || ( e && ( e.message || e.error ) ) || __( 'Outline generation failed.', 'redaquest-connector' ), { id: 'rq-blog' } );
			setStep( 2 );
		} finally {
			setBusyOutline( false );
			setBlogBusy( false );
		}
	};

	const onDraft = async () => {
		const base = buildInputs();
		setBusyDraft( true );
		setBlogBusy( true );
		try {
			const res = await apiFetch( {
				path: '/redaquest/v2/blog/draft',
				method: 'POST',
				data: {
					topic: base.topic,
					approvedOutline: serializeOutline( outline ),
					angle: base.angle,
					keywords: base.keywords,
					length,
					researchContext: research.context || undefined,
					sources: research.sources && research.sources.length ? research.sources : undefined,
				},
			} );
			let bodyBlocks = res.articleHtml ? rawHandler( { HTML: res.articleHtml } ) : [];
			const faqBlocks = [];
			if ( Array.isArray( res.faq ) && res.faq.length ) {
				faqBlocks.push( createBlock( 'core/heading', { level: 2, content: __( 'Často kladené otázky', 'redaquest-connector' ) } ) );
				res.faq.forEach( ( f ) => {
					if ( f && f.q ) faqBlocks.push( createBlock( 'core/heading', { level: 3, content: f.q } ) );
					if ( f && f.a ) faqBlocks.push( createBlock( 'core/paragraph', { content: f.a } ) );
				} );
				// FAQ schema (FAQPage JSON-LD) as a core/html block, so search engines and AI engines
				// read the Q&A as structured data — the same mechanism Yoast/Rank Math use.
				const faqEntities = res.faq
					.filter( ( f ) => f && f.q && f.a )
					.map( ( f ) => ( { '@type': 'Question', name: f.q, acceptedAnswer: { '@type': 'Answer', text: f.a } } ) );
				if ( faqEntities.length ) {
					const faqSchema = { '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: faqEntities };
					faqBlocks.push( createBlock( 'core/html', { content: `<script type="application/ld+json">${ JSON.stringify( faqSchema ) }</script>` } ) );
				}
			}
			// Section images: generate one per flagged section (from that section's text) and splice it
			// after the matching H2. Each image bills credits in the engine.
			let sectionImgCount = 0;
			let imgFailed = 0;
			const flaggedSections = ( outline.sections || [] ).filter( ( s ) => s && s.image && ( s.h2 || '' ).trim() );
			if ( flaggedSections.length && postId ) {
				const norm = ( t ) => stripHtml( String( t || '' ) ).trim().toLowerCase();
				for ( const s of flaggedSections ) {
					let secBody = '';
					const startI = bodyBlocks.findIndex( ( b ) => b.name === 'core/heading' && norm( b.attributes && b.attributes.content ) === norm( s.h2 ) );
					if ( startI >= 0 ) {
						for ( let i = startI + 1; i < bodyBlocks.length; i++ ) {
							const b = bodyBlocks[ i ];
							if ( b.name === 'core/heading' && ( ! b.attributes || ( b.attributes.level || 2 ) <= 2 ) ) break;
							secBody += ' ' + stripHtml( ( b.attributes && b.attributes.content ) || '' );
						}
					}
					secBody = ( secBody.trim() || s.brief || s.h2 ).slice( 0, 1500 );
					const imgRes = await apiFetch( {
						path: '/redaquest/v2/generate-image',
						method: 'POST',
						data: { postId, setFeatured: false, article: { title: s.h2, body: secBody, excerpt: s.brief || '' }, type: imageStyle === 'photo' ? 'photo' : '' },
					} ).catch( () => null );
					if ( ! ( imgRes && imgRes.mediaUrl ) ) imgFailed++;
					if ( imgRes && imgRes.mediaUrl ) {
						const imgBlock = createBlock( 'core/image', { id: imgRes.mediaId, url: imgRes.mediaUrl, alt: imgRes.altText || s.h2, sizeSlug: 'large' } );
						const at = bodyBlocks.findIndex( ( b ) => b.name === 'core/heading' && norm( b.attributes && b.attributes.content ) === norm( s.h2 ) );
						if ( at >= 0 ) bodyBlocks.splice( at + 1, 0, imgBlock ); else bodyBlocks.push( imgBlock );
						sectionImgCount++;
					}
				}
			}

			const all = [ ...bodyBlocks, ...faqBlocks ];
			if ( all.length ) insertBlocks( all );

			// Set the WP post title from the approved outline title (the plugin used to leave it empty).
			const finalTitle = ( outline && outline.title ) ? outline.title : ( res.metaTitle || '' );
			if ( finalTitle ) editPost( { title: finalTitle } );

			let seoPlugin = '';
			if ( postId ) {
				const metaRes = await apiFetch( {
					path: '/redaquest/v2/blog/apply-meta',
					method: 'POST',
					data: { postId, metaTitle: res.metaTitle, metaDescription: res.metaDescription, slug: res.slug, excerpt: res.excerpt },
				} ).catch( () => null );
				if ( metaRes && metaRes.seoPlugin ) seoPlugin = metaRes.seoPlugin;
			}

			// Optional cover illustration (step 4). Reuses the RedaQuest image engine: it derives the
			// concept from the article, applies brand colors (or photoreal), and sets the featured image.
			let imgNote = '';
			if ( genImage && postId ) {
				const imgRes = await apiFetch( {
					path: '/redaquest/v2/generate-image',
					method: 'POST',
					data: {
						postId,
						article: { title: finalTitle || base.topic, body: stripHtml( res.articleHtml ), excerpt: res.excerpt },
						type: imageStyle === 'photo' ? 'photo' : '',
					},
				} ).catch( () => null );
				if ( ! ( imgRes && imgRes.imageUrl ) ) imgFailed++;
				if ( imgRes && imgRes.imageUrl ) {
						imgNote = ` · ${ __( 'cover image added', 'redaquest-connector' ) }`;
						if ( imgRes.featuredMediaId ) editPost( { featured_media: imgRes.featuredMediaId } );
					}
			if ( sectionImgCount ) imgNote += ` · ${ sectionImgCount } ${ __( 'section image(s)', 'redaquest-connector' ) }`;
			}

			if ( imgFailed ) imgNote += ` · ${ imgFailed } ${ __( 'image(s) failed (check credits or brand setup)', 'redaquest-connector' ) }`;
			const seoNote = seoPlugin ? ` · ${ __( 'SEO saved to', 'redaquest-connector' ) } ${ seoPlugin }` : '';
			if ( ! Array.isArray( res.faq ) || res.faq.length === 0 ) {
				createWarningNotice( __( 'Article inserted, but no FAQ was generated.', 'redaquest-connector' ) + creditSuffix( res ) + seoNote + imgNote, { id: 'rq-blog', type: 'snackbar' } );
			} else {
				createSuccessNotice( __( 'Article + FAQ inserted.', 'redaquest-connector' ) + creditSuffix( res ) + seoNote + imgNote, { id: 'rq-blog', type: 'snackbar' } );
			}
			// done → close + reset to a clean slate (the article is now in the editor)
			setBlogOpen( false );
			setStep( 1 );
			setOutline( null );
		} catch ( e ) {
			createErrorNotice( creditError( e ) || ( e && ( e.message || e.error ) ) || __( 'Article generation failed.', 'redaquest-connector' ), { id: 'rq-blog' } );
		} finally {
			setBusyDraft( false );
			setBlogBusy( false );
		}
	};

	const busy = busyOutline || busyDraft;
	const workspaceName = personasInfo.brandName || ( info && info.brandName ) || '';

	// Cost estimate for the final step (the outline was already charged in step 3).
	const sectionImgCount = outline ? ( outline.sections || [] ).filter( ( s ) => s && s.image ).length : 0;
	const imgCost = credits.costs ? Number( credits.costs.image || 0 ) : 0;
	const artCost = credits.costs ? Number( credits.costs.article || 0 ) : 0;
	const estCost = artCost + ( genImage ? imgCost : 0 ) + sectionImgCount * imgCost;
	const notEnough = credits.remaining !== null && !! credits.costs && estCost > credits.remaining;

	// --- styles ---
	const grid = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 16, alignItems: 'end' };
	const fieldLabel = { display: 'block', textTransform: 'uppercase', fontSize: 11, fontWeight: 500, color: '#1A1028', margin: '0 0 8px' };
	const sectionCard = { background: '#fafafc', border: '1px solid #ececf1', borderRadius: 8, padding: '12px 14px', marginBottom: 10 };
	// borderless, "reads like text" inputs for the outline
	const titleInput = { width: '100%', border: 'none', borderBottom: '1px dashed transparent', background: 'transparent', fontSize: 15, fontWeight: 600, padding: '2px 0', outline: 'none' };
	const briefArea = { width: '100%', border: 'none', background: 'transparent', resize: 'vertical', fontSize: 13, color: '#444', lineHeight: 1.5, padding: '2px 0', outline: 'none', minHeight: 38 };

	// Label text + a hover ⓘ tooltip — a compact brand box (styled in editor.css .rq-tip),
	// not WordPress's full-width Tooltip bar.
	const tip = ( text, help ) => (
		<span style={ { display: 'inline-flex', alignItems: 'center', gap: 6 } }>
			{ text }
			<span className="rq-tip" data-tip={ help } tabIndex={ 0 } role="img" aria-label={ help }>i</span>
		</span>
	);

	const steps = [ __( 'Topic', 'redaquest-connector' ), __( 'Audience', 'redaquest-connector' ), __( 'Outline', 'redaquest-connector' ), __( 'Image', 'redaquest-connector' ) ];
	const stepDot = ( n ) => ( {
		width: 22, height: 22, borderRadius: '50%', display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
		fontSize: 12, fontWeight: 600, color: '#fff', background: step === n ? '#E24260' : ( step > n ? '#6C5CE7' : '#DAD2C7' ),
	} );

	const Loading = ( label ) => (
		<div style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 12, padding: '64px 0', color: '#555' } }>
			<Spinner style={ { width: 36, height: 36 } } />
			<p style={ { margin: 0, fontWeight: 500 } }>{ label }</p>
			<p style={ { margin: 0, fontSize: 12, color: '#888' } }>{ __( 'Generating can take 3 to 4 minutes, more with images. You can close this window, it keeps running in the background.', 'redaquest-connector' ) }</p>
		</div>
	);

	if ( ! open ) return null;

	return (
		<Modal className="redaquest-blog-modal" __experimentalHideHeader aria-label={ __( 'RedaQuest Blog Writer', 'redaquest-connector' ) } onRequestClose={ () => setBlogOpen( false ) } shouldCloseOnClickOutside={ false } style={ { width: 'min(1080px, 92vw)' } }>
			{/* compact header: title + inline stepper + close, all on one row to save height */}
			<div style={ { display: 'flex', alignItems: 'center', gap: 18, margin: '0 0 18px', flexWrap: 'wrap' } }>
				<strong style={ { fontSize: 16 } }>RedaQuest</strong>
				{ connected && (
					<div style={ { display: 'flex', gap: 14, alignItems: 'center' } }>
						{ steps.map( ( label, i ) => {
							const n = i + 1;
							return (
								<div key={ n } style={ { display: 'flex', alignItems: 'center', gap: 6, opacity: step === n || step > n ? 1 : 0.5 } }>
									<span style={ stepDot( n ) }>{ step > n ? '✓' : n }</span>
									<span style={ { fontSize: 13, fontWeight: step === n ? 600 : 400 } }>{ label }</span>
								</div>
							);
						} ) }
					</div>
				) }
				{ connected && workspaceName && (
					<span title={ __( 'Connected workspace', 'redaquest-connector' ) } style={ { marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#7A7486', background: '#FBE7EB', borderRadius: 999, padding: '4px 10px' } }>
						<span style={ { width: 7, height: 7, borderRadius: '50%', background: '#E24260' } } />
						{ workspaceName }
					</span>
				) }
				<Button variant="tertiary" onClick={ () => setBlogOpen( false ) } aria-label={ __( 'Close', 'redaquest-connector' ) } style={ { marginLeft: ( connected && workspaceName ) ? 8 : 'auto', fontSize: 22, lineHeight: 1, padding: '0 8px' } }>×</Button>
			</div>

			{ ! connected ? (
				<div style={ { padding: '24px 0' } }>
					<p>{ __( 'Connect this site to RedaQuest to use the blog writer (Settings → Redaquest Connector → Connection).', 'redaquest-connector' ) }</p>
					{ status && status.connectUrl && (
						<Button variant="primary" href={ status.connectUrl }>{ __( 'Connect RedaQuest', 'redaquest-connector' ) }</Button>
					) }
				</div>
			) : ( <>
					{/* STEP 1 — topic */}
					{ step === 1 && (
						<div>
							<div style={ grid }>
								<TextControl label={ tip( __( 'Topic', 'redaquest-connector' ), __( 'The subject to cover. A full sentence or question works great, e.g. “How to write LinkedIn posts with AI that don’t sound robotic.”', 'redaquest-connector' ) ) } value={ topic } onChange={ setTopic } placeholder={ currentTitle || __( 'What is the article about?', 'redaquest-connector' ) } />
								<div>
									<span style={ fieldLabel }>{ tip( __( 'Length', 'redaquest-connector' ), __( 'Detailed: a focused 800 to 1200 word article on one topic. Cornerstone: a long, in-depth pillar (2000+ words) that other posts link to.', 'redaquest-connector' ) ) }</span>
									<ButtonGroup className="rq-segment" style={ { display: 'flex', width: '100%' } }>
										<Button variant={ length === 'spoke' ? 'primary' : 'secondary' } onClick={ () => setLength( 'spoke' ) } style={ { flex: 1, justifyContent: 'center', height: 40 } }>{ __( 'Detailed', 'redaquest-connector' ) }</Button>
										<Button variant={ length === 'pillar' ? 'primary' : 'secondary' } onClick={ () => setLength( 'pillar' ) } style={ { flex: 1, justifyContent: 'center', height: 40 } }>{ __( 'Cornerstone', 'redaquest-connector' ) }</Button>
									</ButtonGroup>
								</div>
								<TextControl label={ tip( __( 'Keywords', 'redaquest-connector' ), __( '1 to 3 phrases to rank for, comma-separated. Leave empty to let AI choose.', 'redaquest-connector' ) ) } value={ keywords } onChange={ setKeywords } placeholder={ __( 'e.g. linkedin, content marketing', 'redaquest-connector' ) } />
							</div>

							<div style={ { marginTop: 16 } }>
								<Button variant="link" className="rq-link" onClick={ () => setShowSource( ! showSource ) }>
									{ showSource ? __( '− Hide existing content', 'redaquest-connector' ) : __( '＋ Base it on existing content (paste or upload a doc)', 'redaquest-connector' ) }
								</Button>
								{ showSource && (
									<div style={ { marginTop: 8 } }>
										<TextareaControl
											label={ tip( __( 'Existing content', 'redaquest-connector' ), __( 'Paste a draft, notes or a whole document (Word, Google Docs or Markdown, just copy and paste). The article is rewritten and expanded from it, and keeps your facts.', 'redaquest-connector' ) ) }
											value={ sourceContent }
											onChange={ setSourceContent }
											rows={ 6 }
											placeholder={ __( 'Paste your existing text here…', 'redaquest-connector' ) }
										/>
										<div style={ { display: 'flex', alignItems: 'center', gap: 12, marginTop: 4 } }>
											<label className="rq-file-btn">
												{ __( 'Upload .md / .txt / .html', 'redaquest-connector' ) }
												<input type="file" accept=".md,.markdown,.txt,.text,.html,.htm" onChange={ onSourceFile } style={ { display: 'none' } } />
											</label>
											{ !! sourceContent && <span style={ { fontSize: 12, color: '#7A7486' } }>{ sourceContent.length } { __( 'chars', 'redaquest-connector' ) }</span> }
											{ !! sourceContent && <Button variant="link" isDestructive onClick={ () => setSourceContent( '' ) } style={ { fontSize: 12 } }>{ __( 'Clear', 'redaquest-connector' ) }</Button> }
										</div>
										<p style={ { fontSize: 12, color: '#7A7486', margin: '6px 0 0' } }>{ __( 'For Word or Google Docs, copy the text and paste it above.', 'redaquest-connector' ) }</p>
									</div>
								) }
							</div>

							<div style={ { marginTop: 22, display: 'flex', justifyContent: 'flex-end' } }>
								<Button variant="primary" onClick={ goToAudience } style={ { height: 40 } }>{ __( 'Continue', 'redaquest-connector' ) } →</Button>
							</div>
						</div>
					) }

					{/* STEP 2 — audience & angle */}
					{ step === 2 && (
						<div>
							<p style={ { margin: '0 0 12px', fontSize: 13, color: '#666' } }>{ __( 'Pick who you’re writing for, and optionally add your own angle. It is all optional, and it improves quality.', 'redaquest-connector' ) }</p>

							{ loadingPersonas && <p style={ { color: '#666' } }><Spinner /> { __( 'Loading personas…', 'redaquest-connector' ) }</p> }
							{ ! loadingPersonas && personas !== null && (
								personas.length > 0 ? (
									<div style={ { marginBottom: 16 } }>
										<span style={ fieldLabel }>{ tip( __( 'Target persona', 'redaquest-connector' ), __( 'Personas come from your RedaQuest communication manual. The article is written for the one you pick.', 'redaquest-connector' ) ) }</span>
										<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: 10 } }>
											{ personas.map( ( p ) => {
												const sel = selectedPersonaId === p.id;
												const full = [ p.profile, p.situation ].filter( Boolean ).join( '\n\n' );
												return (
													<button
														type="button"
														key={ p.id }
														onClick={ () => selectPersona( p ) }
														title={ full }
														style={ {
															textAlign: 'left', cursor: 'pointer', padding: '10px 12px', borderRadius: 8,
															border: sel ? '2px solid #E24260' : '1px solid #E7E1D9', background: sel ? '#FBE7EB' : '#fff',
														} }
													>
														<div style={ { fontWeight: 600, fontSize: 13, color: '#1A1028', lineHeight: 1.25 } }>{ p.name }</div>
														{ p.age && <div style={ { fontSize: 11, color: '#7A7486', margin: '2px 0 4px' } }>{ p.age }</div> }
														{ p.profile && <div style={ { display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden', fontSize: 12, color: '#5E586B', lineHeight: 1.4 } }>{ p.profile }</div> }
													</button>
												);
											} ) }
											<button
												type="button"
												onClick={ () => { setSelectedPersonaId( '' ); setAudience( '' ); } }
												style={ { cursor: 'pointer', padding: '10px 12px', borderRadius: 8, border: selectedPersonaId === '' ? '2px solid #E24260' : '1px dashed #DAD2C7', background: selectedPersonaId === '' ? '#FBE7EB' : '#fff', color: '#5E586B', fontWeight: 500, fontSize: 13, display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center' } }
											>
												{ __( 'No specific persona', 'redaquest-connector' ) }
											</button>
										</div>
									</div>
								) : (
									<p style={ { fontSize: 13, color: '#8a6d3b', background: '#fff8e5', borderLeft: '4px solid #dba617', padding: '8px 12px', borderRadius: 4 } }>
										{ personasInfo.hasManual
											? __( 'No personas in your brand manual yet. You can still add your own audience below.', 'redaquest-connector' )
											: __( 'No brand manual found in this workspace. Add one in RedaQuest for on-brand, audience-aware articles.', 'redaquest-connector' ) }
									</p>
								)
							) }

							<div style={ grid }>
								<TextareaControl label={ tip( __( 'Your thesis / opinion', 'redaquest-connector' ), __( 'Your own claim or stance. It makes the article original, not generic AI text.', 'redaquest-connector' ) ) } value={ thesis } onChange={ setThesis } rows={ 3 } />
								<TextareaControl label={ tip( __( 'Example / experience', 'redaquest-connector' ), __( 'A real example or experience from practice for the AI to use.', 'redaquest-connector' ) ) } value={ example } onChange={ setExample } rows={ 3 } />
							</div>
							<div style={ { marginTop: 4 } }>
								<TextareaControl label={ tip( __( 'Source URLs', 'redaquest-connector' ), __( 'Links the AI should use as sources, one per line. Optional. It also searches the web if that is on.', 'redaquest-connector' ) ) } value={ sourceUrls } onChange={ setSourceUrls } rows={ 2 } placeholder={ 'https://…' } />
							</div>
							<div style={ { marginTop: 10 } }>
								<ToggleControl label={ __( 'Web research (find facts & sources)', 'redaquest-connector' ) } checked={ webResearch } onChange={ setWebResearch } />
							</div>

							<div style={ { marginTop: 20, display: 'flex', justifyContent: 'space-between' } }>
								<Button variant="tertiary" onClick={ () => setStep( 1 ) }>← { __( 'Back', 'redaquest-connector' ) }</Button>
								<Button variant="primary" onClick={ onOutline } disabled={ busy }>{ __( 'Generate outline', 'redaquest-connector' ) } →</Button>
							</div>
						</div>
					) }

					{/* STEP 3 — outline + generate */}
					{ step === 3 && (
						busyOutline || ! outline ? (
							Loading( __( 'Preparing your outline…', 'redaquest-connector' ) )
						) : busyDraft ? (
							Loading( __( 'Writing your article…', 'redaquest-connector' ) )
						) : (
							<div>
								<label style={ { fontSize: 11, textTransform: 'uppercase', color: '#888', letterSpacing: '.04em' } }>{ __( 'Title', 'redaquest-connector' ) }</label>
								<input style={ titleInput } value={ outline.title || '' } onChange={ ( e ) => patchOutline( 'title', e.target.value ) } placeholder={ __( 'Article title', 'redaquest-connector' ) } />
								<textarea style={ { ...briefArea, marginTop: 6, marginBottom: 14 } } value={ outline.answerParagraph || '' } onChange={ ( e ) => patchOutline( 'answerParagraph', e.target.value ) } placeholder={ __( 'Short intro answer…', 'redaquest-connector' ) } />

								{ ( outline.sections || [] ).map( ( s, idx ) => (
									<div key={ idx } style={ sectionCard }>
										<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 } }>
											<span style={ { fontSize: 11, textTransform: 'uppercase', color: '#888', letterSpacing: '.04em' } }>{ __( 'Section', 'redaquest-connector' ) } { idx + 1 }</span>
											<div style={ { display: 'flex', alignItems: 'center', gap: 10 } }>
												<span className="rq-tip" data-tip={ __( 'We read this section and generate an illustration of what it is about. Each image costs credits.', 'redaquest-connector' ) } tabIndex={ 0 } role="img" aria-label={ __( 'About section images', 'redaquest-connector' ) }>i</span>
												<Button variant={ s.image ? 'primary' : 'secondary' } onClick={ () => patchSection( idx, 'image', ! s.image ) } style={ { height: 26, fontSize: 11, padding: '0 10px' } }>
													<><IconImage />{ s.image ? __( 'Image on', 'redaquest-connector' ) : __( 'Generate image', 'redaquest-connector' ) }</>
												</Button>
												<Button variant="link" isDestructive onClick={ () => removeSection( idx ) } style={ { padding: 0, fontSize: 12 } }>{ __( 'Remove section', 'redaquest-connector' ) }</Button>
											</div>
										</div>
										<input style={ titleInput } value={ s.h2 || '' } onChange={ ( e ) => patchSection( idx, 'h2', e.target.value ) } placeholder={ __( 'Section heading', 'redaquest-connector' ) } />
										<textarea style={ briefArea } value={ s.brief || '' } onChange={ ( e ) => patchSection( idx, 'brief', e.target.value ) } placeholder={ __( 'What this section covers…', 'redaquest-connector' ) } />
									</div>
								) ) }
								<Button variant="secondary" onClick={ addSection } style={ { marginBottom: 14 } }>{ __( '+ Add section', 'redaquest-connector' ) }</Button>

								<p style={ { fontWeight: 600, margin: '6px 0 6px' } }>{ __( 'FAQ questions', 'redaquest-connector' ) }</p>
								{ ( outline.faqPlan || [] ).map( ( q, idx ) => (
									<div key={ idx } style={ { display: 'flex', gap: 6, alignItems: 'center', marginBottom: 6 } }>
										<input style={ { ...titleInput, fontWeight: 400, fontSize: 13, borderBottom: '1px solid #eee' } } value={ q } onChange={ ( e ) => patchFaq( idx, e.target.value ) } placeholder={ __( 'Question readers ask', 'redaquest-connector' ) } />
										<Button variant="link" isDestructive onClick={ () => removeFaq( idx ) } style={ { padding: '0 4px' } }>×</Button>
									</div>
								) ) }
								<Button variant="secondary" onClick={ addFaq } style={ { marginBottom: 14 } }>{ __( '+ Add question', 'redaquest-connector' ) }</Button>

								<p style={ { fontSize: 12, color: '#888', margin: '8px 0 0' } }>{ __( 'SEO meta is generated automatically and saved to your SEO plugin.', 'redaquest-connector' ) }</p>

								<div style={ { marginTop: 16, borderTop: '1px solid #eee', paddingTop: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
									<Button variant="tertiary" onClick={ () => setStep( 2 ) }>← { __( 'Back', 'redaquest-connector' ) }</Button>
									<Button variant="primary" onClick={ () => setStep( 4 ) } style={ { height: 40 } }>{ __( 'Continue', 'redaquest-connector' ) } →</Button>
								</div>
							</div>
						)
					) }
						{/* STEP 4 — images + generate */}
						{ step === 4 && (
							busyDraft ? (
								Loading( __( 'Writing your article…', 'redaquest-connector' ) )
							) : (
								<div>
									<p style={ { margin: '0 0 10px', fontWeight: 600 } }>{ __( 'Cover image', 'redaquest-connector' ) }</p>
									<ToggleControl
										label={ __( 'Generate a cover illustration for this article', 'redaquest-connector' ) }
										help={ __( 'Created from the article and set as the featured image automatically.', 'redaquest-connector' ) }
										checked={ genImage }
										onChange={ setGenImage }
									/>
									{ genImage && (
										<div style={ { marginTop: 10, maxWidth: 440 } }>
											<span style={ fieldLabel }>{ tip( __( 'Image style', 'redaquest-connector' ), __( 'From brand manual: your brand visual style and colors. Photorealistic: a real photo. The scene comes from the article.', 'redaquest-connector' ) ) }</span>
											<ButtonGroup className="rq-segment" style={ { display: 'flex', width: '100%' } }>
												<Button variant={ imageStyle === 'brand' ? 'primary' : 'secondary' } onClick={ () => setImageStyle( 'brand' ) } style={ { flex: 1, justifyContent: 'center', height: 40 } }>{ __( 'From brand manual', 'redaquest-connector' ) }</Button>
												<Button variant={ imageStyle === 'photo' ? 'primary' : 'secondary' } onClick={ () => setImageStyle( 'photo' ) } style={ { flex: 1, justifyContent: 'center', height: 40 } }>{ __( 'Photorealistic', 'redaquest-connector' ) }</Button>
											</ButtonGroup>
										</div>
									) }
									{ ( outline.sections || [] ).some( ( s ) => s && s.image ) && (
										<p style={ { fontSize: 13, color: '#1A1028', background: '#FBE7EB', borderRadius: 8, padding: '8px 12px', margin: '16px 0 0' } }>
											{ ( outline.sections || [] ).filter( ( s ) => s && s.image ).length } { __( 'section image(s) selected. Each one is generated from its section and inserted after its heading.', 'redaquest-connector' ) }
										</p>
									) }
									{ credits.remaining !== null && (
										<div style={ { margin: '16px 0 0', padding: '10px 14px', borderRadius: 8, background: notEnough ? '#FBE7EB' : '#F7F5F2', border: '1px solid ' + ( notEnough ? '#E24260' : '#E7E1D9' ), fontSize: 13 } }>
											<div style={ { color: '#1A1028', fontSize: 15 } }><strong>{ estCost } { __( 'credits', 'redaquest-connector' ) }</strong> { __( 'from', 'redaquest-connector' ) } { credits.remaining } { __( 'remaining', 'redaquest-connector' ) }</div>
											{ credits.costs && (
												<div style={ { color: '#5E586B', marginTop: 4 } }>{ `${ __( 'article', 'redaquest-connector' ) } ${ artCost }${ genImage ? ` + ${ __( 'cover', 'redaquest-connector' ) } ${ imgCost }` : '' }${ sectionImgCount ? ` + ${ sectionImgCount }×${ imgCost } ${ __( 'sections', 'redaquest-connector' ) }` : '' }` }</div>
											) }
											{ notEnough && <div style={ { color: '#DC2626', marginTop: 4 } }>{ __( 'Not enough credits. Top up in RedaQuest to continue.', 'redaquest-connector' ) }</div> }
										</div>
									) }
									<p style={ { fontSize: 12, color: '#7A7486', margin: '14px 0 0' } }>{ __( 'On generate: the article and FAQ are inserted, the title and SEO meta are set, and the selected images are generated. This can take 3 to 4 minutes. Each image uses credits.', 'redaquest-connector' ) }</p>
									<div style={ { marginTop: 16, borderTop: '1px solid #eee', paddingTop: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
										<Button variant="tertiary" onClick={ () => setStep( 3 ) }>← { __( 'Back', 'redaquest-connector' ) }</Button>
										<Button variant="primary" onClick={ onDraft } disabled={ notEnough } style={ { minHeight: 40, fontSize: 14 } }>{ __( 'Generate full article', 'redaquest-connector' ) }</Button>
									</div>
								</div>
							)
						) }

			</> ) }
		</Modal>
	);
}

// Fallback launcher: a native panel in the document sidebar (always available even if the header
// injection below fails on some theme/WP version). Opens the same sheet via the shared store flag.
function RedaQuestBlogLauncherPanel() {
	const { setBlogOpen } = useDispatch( STORE );
	return (
		<PluginDocumentSettingPanel name="redaquest-blog" title={ __( 'RedaQuest', 'redaquest-connector' ) }>
			<Button className="rq-sidebar-cta" variant="primary" onClick={ () => setBlogOpen( true ) } style={ { width: '100%', justifyContent: 'center', height: 38 } }>
				<><IconPencil />{ __( 'Write an article', 'redaquest-connector' ) }</>
			</Button>
			<p style={ { fontSize: 12, color: '#777', marginTop: 8 } }>{ __( 'Generate a GEO article from your brand voice & audience.', 'redaquest-connector' ) }</p>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'redaquest-connector', {
	render: () => (
		<>
			<RedaQuestPanel />
			<RedaQuestPrePublish />
			<RedaQuestBlogModal />
			<RedaQuestBlogLauncherPanel />
		</>
	),
} );

// Primary launcher: a prominent button in the editor header (top, always visible — like "Edit with
// <page builder>"). The flow lives in the always-mounted RedaQuestBlogModal; this button just flips
// the shared store flag, so the sidebar panel above stays a working fallback if the header DOM shifts.
function RedaquestHeaderButton() {
	const busy = useSelect( ( select ) => select( STORE ).isBlogBusy(), [] );
	return (
		<Button variant="primary" className="redaquest-header-cta" onClick={ () => dataDispatch( STORE ).setBlogOpen( true ) } style={ { height: 32 } }>
			{ busy ? <Spinner /> : <IconPencil /> }{ busy ? __( 'Generating…', 'redaquest-connector' ) : __( 'RedaQuest', 'redaquest-connector' ) }
		</Button>
	);
}

function mountRedaquestHeaderButton( attempts ) {
	if ( document.getElementById( 'redaquest-header-cta-root' ) ) return;
	const host = document.querySelector( '.editor-header__center, .edit-post-header__center, .editor-header__toolbar, .edit-post-header__toolbar' );
	if ( host ) {
		const el = document.createElement( 'div' );
		el.id = 'redaquest-header-cta-root';
		el.style.display = 'inline-flex';
		el.style.alignItems = 'center';
		el.style.marginLeft = '8px';
		host.appendChild( el );
		if ( typeof createRoot === 'function' ) {
			createRoot( el ).render( <RedaquestHeaderButton /> );
		} else if ( typeof wpRender === 'function' ) {
			wpRender( <RedaquestHeaderButton />, el );
		}
		return;
	}
	if ( ( attempts || 0 ) < 80 ) {
		setTimeout( () => mountRedaquestHeaderButton( ( attempts || 0 ) + 1 ), 150 );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => mountRedaquestHeaderButton( 0 ) );
} else {
	mountRedaquestHeaderButton( 0 );
}
