/**
 * BudgetPixel AI Image — block-editor sidebar panel.
 *
 * Plain JS on the wp.* globals (no JSX, no build step) so the shipped file is
 * the source file. Flow: prompt (pre-filled from title + excerpt) → model +
 * aspect → live credit estimate → generate → poll → preview → set as featured
 * image or insert into the post. All API calls go through the plugin's REST
 * proxy; the API key never reaches this script.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var C = wp.components;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var cfg = window.BudgetPixelEditor || {};
	var POLL_MS = 2500;
	var POLL_MAX_MS = 5 * 60 * 1000;

	if ( ! Panel ) {
		return;
	}

	// Title + excerpt (or the first 40 words), plus a style hint. Without the
	// hint, models tend to paint the title as (garbled) lettering across the
	// picture — a blog hero wants the subject, not the headline.
	var STYLE_HINT = __( 'Editorial illustration for a blog post, no text or lettering.', 'budgetpixel-ai-images' );
	function defaultPrompt( title, excerpt, content ) {
		var text = ( title || '' ).trim();
		if ( ! text ) {
			return '';
		}
		var body = ( excerpt || '' ).trim();
		if ( ! body && content ) {
			body = content.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim().split( ' ' ).slice( 0, 40 ).join( ' ' );
		}
		return ( body ? text + '. ' + body : text ).replace( /[.\s]+$/, '' ) + '. ' + STYLE_HINT;
	}

	function BudgetPixelPanel() {
		var post = useSelect( function ( select ) {
			var e = select( 'core/editor' );
			return {
				id: e.getCurrentPostId(),
				title: e.getEditedPostAttribute( 'title' ),
				excerpt: e.getEditedPostAttribute( 'excerpt' ),
				content: e.getEditedPostAttribute( 'content' ),
				featured: e.getEditedPostAttribute( 'featured_media' ),
			};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		var insertBlocks = useDispatch( 'core/block-editor' ).insertBlocks;

		var _p = useState( '' ), prompt = _p[ 0 ], setPrompt = _p[ 1 ];
		var _m = useState( cfg.defaultModel || '' ), model = _m[ 0 ], setModel = _m[ 1 ];
		var _a = useState( cfg.defaultAspect || '16:9' ), aspect = _a[ 0 ], setAspect = _a[ 1 ];
		var _models = useState( [] ), models = _models[ 0 ], setModels = _models[ 1 ];
		var _cost = useState( null ), cost = _cost[ 0 ], setCost = _cost[ 1 ];
		var _credits = useState( null ), credits = _credits[ 0 ], setCredits = _credits[ 1 ];
		var _busy = useState( '' ), busy = _busy[ 0 ], setBusy = _busy[ 1 ]; // '', 'generating', 'attaching'
		var _job = useState( null ), job = _job[ 0 ], setJob = _job[ 1 ]; // { id, status, images }
		var _err = useState( '' ), err = _err[ 0 ], setErr = _err[ 1 ];
		var _ok = useState( '' ), ok = _ok[ 0 ], setOk = _ok[ 1 ];
		var _dirty = useState( false ), dirty = _dirty[ 0 ], setDirty = _dirty[ 1 ];
		var pollTimer = useRef( null );

		// Follow the title/excerpt (a new post's title arrives one keystroke at a
		// time) until the user edits the prompt themselves; then leave it alone.
		useEffect( function () {
			if ( dirty || busy ) {
				return;
			}
			setPrompt( defaultPrompt( post.title, post.excerpt, post.content ) );
		}, [ post.title, post.excerpt, dirty ] );

		useEffect( function () {
			if ( ! cfg.hasKey ) {
				return;
			}
			apiFetch( { path: '/budgetpixel/v1/models' } ).then( function ( list ) {
				setModels( Array.isArray( list ) ? list : [] );
				if ( Array.isArray( list ) && list.length && ! list.some( function ( m ) { return m.name === model; } ) ) {
					setModel( list[ 0 ].name );
				}
			} ).catch( function ( e ) { setErr( e && e.message ? e.message : __( 'Could not load models.', 'budgetpixel-ai-images' ) ); } );
			apiFetch( { path: '/budgetpixel/v1/credits' } ).then( function ( c ) {
				setCredits( c && typeof c.total_available === 'number' ? c.total_available : null );
			} ).catch( function () {} );
		}, [] );

		// Live estimate whenever model/aspect change.
		useEffect( function () {
			if ( ! cfg.hasKey || ! model ) {
				return;
			}
			var cancelled = false;
			setCost( null );
			apiFetch( { path: '/budgetpixel/v1/cost', method: 'POST', data: { model: model, aspect_ratio: aspect, prompt: prompt || 'cost estimate' } } )
				.then( function ( r ) { if ( ! cancelled ) { setCost( r ); } } )
				.catch( function () { if ( ! cancelled ) { setCost( null ); } } );
			return function () { cancelled = true; };
		}, [ model, aspect ] );

		useEffect( function () {
			return function () { if ( pollTimer.current ) { clearTimeout( pollTimer.current ); } };
		}, [] );

		function poll( jobId, startedAt ) {
			apiFetch( { path: '/budgetpixel/v1/jobs/' + encodeURIComponent( jobId ) } ).then( function ( j ) {
				if ( j.status === 'succeeded' ) {
					setJob( { id: jobId, status: 'succeeded', images: j.images || [] } );
					setBusy( '' );
					apiFetch( { path: '/budgetpixel/v1/credits' } ).then( function ( c ) { setCredits( c.total_available ); } ).catch( function () {} );
					return;
				}
				if ( j.status === 'failed' || j.status === 'timeout' ) {
					setBusy( '' );
					setJob( null );
					setErr( j.error || __( 'Generation failed. You were not charged.', 'budgetpixel-ai-images' ) );
					return;
				}
				if ( Date.now() - startedAt > POLL_MAX_MS ) {
					setBusy( '' );
					setErr( __( 'Still processing after 5 minutes — check your BudgetPixel history.', 'budgetpixel-ai-images' ) );
					return;
				}
				setJob( { id: jobId, status: j.status, images: [] } );
				pollTimer.current = setTimeout( function () { poll( jobId, startedAt ); }, POLL_MS );
			} ).catch( function ( e ) {
				setBusy( '' );
				setErr( e && e.message ? e.message : __( 'Lost contact with the API.', 'budgetpixel-ai-images' ) );
			} );
		}

		function generate() {
			setErr( '' ); setOk( '' ); setJob( null );
			setBusy( 'generating' );
			apiFetch( { path: '/budgetpixel/v1/generate', method: 'POST', data: { prompt: prompt, model: model, aspect_ratio: aspect, post_id: post.id } } )
				.then( function ( r ) {
					if ( ! r.job_id ) { throw new Error( __( 'The API did not return a job id.', 'budgetpixel-ai-images' ) ); }
					setJob( { id: r.job_id, status: r.status || 'pending', images: [] } );
					poll( r.job_id, Date.now() );
				} )
				.catch( function ( e ) { setBusy( '' ); setErr( e && e.message ? e.message : __( 'Generation failed.', 'budgetpixel-ai-images' ) ); } );
		}

		function attach( position, featured ) {
			setErr( '' ); setOk( '' );
			setBusy( 'attaching' );
			apiFetch( { path: '/budgetpixel/v1/attach', method: 'POST', data: { job_id: job.id, position: position, post_id: post.id, alt: prompt.slice( 0, 240 ), featured: featured } } )
				.then( function ( r ) {
					setBusy( '' );
					if ( featured ) {
						editPost( { featured_media: r.attachment_id } );
						setOk( __( 'Featured image set. Save the post to keep it.', 'budgetpixel-ai-images' ) );
					} else {
						insertBlocks( wp.blocks.createBlock( 'core/image', { id: r.attachment_id, url: r.url, alt: prompt.slice( 0, 240 ) } ) );
						setOk( __( 'Image inserted into the post.', 'budgetpixel-ai-images' ) );
					}
				} )
				.catch( function ( e ) { setBusy( '' ); setErr( e && e.message ? e.message : __( 'Could not save the image.', 'budgetpixel-ai-images' ) ); } );
		}

		if ( ! cfg.hasKey ) {
			return el( Panel, { name: 'budgetpixel-panel', title: __( 'BudgetPixel AI Image', 'budgetpixel-ai-images' ), className: 'budgetpixel-panel' },
				el( 'p', null, __( 'Add your BudgetPixel API key to generate images here.', 'budgetpixel-ai-images' ) ),
				el( C.Button, { variant: 'secondary', href: cfg.settingsUrl }, __( 'Open settings', 'budgetpixel-ai-images' ) )
			);
		}

		var modelOptions = models.length
			? models.map( function ( m ) { return { value: m.name, label: m.credits ? m.name + ' · ' + m.credits + ' cr' : m.name }; } )
			: [ { value: model, label: model } ];
		var costLabel = cost && typeof cost.credits === 'number'
			? sprintf( cost.exact ? __( '%s credits per image', 'budgetpixel-ai-images' ) : __( '≈ %s credits per image', 'budgetpixel-ai-images' ), cost.credits.toLocaleString() )
			: __( 'Estimating cost…', 'budgetpixel-ai-images' );
		var working = busy === 'generating';

		return el( Panel, { name: 'budgetpixel-panel', title: __( 'BudgetPixel AI Image', 'budgetpixel-ai-images' ), className: 'budgetpixel-panel' },
			el( C.TextareaControl, { label: __( 'Prompt', 'budgetpixel-ai-images' ), value: prompt, rows: 4, onChange: function ( v ) { setDirty( true ); setPrompt( v ); }, help: __( 'Pre-filled from the title and excerpt. Describe the picture, not the article.', 'budgetpixel-ai-images' ) } ),
			el( C.SelectControl, { label: __( 'Model', 'budgetpixel-ai-images' ), value: model, options: modelOptions, onChange: setModel } ),
			el( C.SelectControl, { label: __( 'Aspect ratio', 'budgetpixel-ai-images' ), value: aspect, options: ( cfg.aspects || [ '16:9', '1:1' ] ).map( function ( a ) { return { value: a, label: a }; } ), onChange: setAspect } ),
			el( 'p', { className: 'budgetpixel-cost' },
				el( 'strong', null, costLabel ),
				credits !== null ? el( 'span', { className: 'budgetpixel-balance' }, ' · ' + sprintf( __( '%s credits available', 'budgetpixel-ai-images' ), credits.toLocaleString() ) ) : null,
				' · ', el( 'a', { href: cfg.pricingUrl, target: '_blank', rel: 'noopener' }, __( 'prices', 'budgetpixel-ai-images' ) )
			),
			el( C.Button, { variant: 'primary', isBusy: working, disabled: working || ! prompt.trim() || ! model, onClick: generate },
				working ? ( job && job.status ? sprintf( __( 'Generating… (%s)', 'budgetpixel-ai-images' ), job.status ) : __( 'Generating…', 'budgetpixel-ai-images' ) ) : __( 'Generate image', 'budgetpixel-ai-images' ) ),
			err ? el( C.Notice, { status: 'error', isDismissible: true, onRemove: function () { setErr( '' ); } }, err ) : null,
			ok ? el( C.Notice, { status: 'success', isDismissible: true, onRemove: function () { setOk( '' ); } }, ok ) : null,
			job && job.status === 'succeeded' && job.images.length
				? el( 'div', { className: 'budgetpixel-results' },
					job.images.map( function ( img ) {
						return el( 'div', { key: img.position, className: 'budgetpixel-result' },
							el( 'img', { src: img.url, alt: '' } ),
							el( 'div', { className: 'budgetpixel-result-actions' },
								el( C.Button, { variant: 'primary', isBusy: busy === 'attaching', disabled: !! busy, onClick: function () { attach( img.position, true ); } }, __( 'Set as featured image', 'budgetpixel-ai-images' ) ),
								el( C.Button, { variant: 'secondary', disabled: !! busy, onClick: function () { attach( img.position, false ); } }, __( 'Insert into post', 'budgetpixel-ai-images' ) )
							)
						);
					} )
				)
				: null
		);
	}

	wp.plugins.registerPlugin( 'budgetpixel-ai-image', { render: BudgetPixelPanel, icon: null } );
} )( window.wp );
