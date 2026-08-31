import { PanelBody, Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import SuggestionItem from './SuggestionItem';

const Sidebar = () => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ error, setError ] = useState( '' );

	const { postContent, currentPostId, blocks } = useSelect(
		( select ) => ( {
			postContent: select( 'core/editor' ).getEditedPostContent(),
			currentPostId: select( 'core/editor' ).getCurrentPostId(),
			blocks: select( 'core/block-editor' ).getBlocks(),
		} ),
		[]
	);

	const { updateBlockAttributes } = useDispatch( 'core/block-editor' );

	const handleFindRelated = () => {
		if ( ! postContent || postContent.trim().length === 0 ) {
			setError(
				__(
					'Cannot generate suggestions for an empty post.',
					'contextual-link-weaver'
				)
			);
			return;
		}

		setIsLoading( true );
		setError( '' );
		setSuggestions( [] );

		apiFetch( {
			path: '/contextual-link-weaver/v1/suggestions',
			method: 'POST',
			data: {
				content: postContent,
				post_id: currentPostId,
			},
		} )
			.then( ( response ) => {
				if ( Array.isArray( response ) ) {
					setSuggestions( response );
					if ( response.length === 0 ) {
						setError(
							__(
								'No link suggestions found for this content.',
								'contextual-link-weaver'
							)
						);
					}
				} else if ( response.error ) {
					setError( response.error );
				} else {
					setError(
						__(
							'The API returned an unexpected format.',
							'contextual-link-weaver'
						)
					);
				}
				setIsLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'An unknown error occurred.',
							'contextual-link-weaver'
						)
				);
				setIsLoading( false );
			} );
	};

	return (
		<PanelBody
			title={ __( 'Link Suggestions', 'contextual-link-weaver' ) }
		>
			<p style={ { fontSize: '12px', color: '#555' } }>
				{ __(
					'Analyze your content to find semantically related posts and generate link suggestions.',
					'contextual-link-weaver'
				) }
			</p>
			<Button
				variant="primary"
				onClick={ handleFindRelated }
				isBusy={ isLoading }
				disabled={ isLoading }
			>
				{ isLoading
					? __( 'Analyzing...', 'contextual-link-weaver' )
					: __( 'Find Related Posts', 'contextual-link-weaver' ) }
			</Button>

			{ isLoading && <Spinner style={ { marginTop: '10px' } } /> }

			{ error && (
				<p style={ { color: '#d63638', marginTop: '10px', fontSize: '13px' } }>
					{ error }
				</p>
			) }

			{ suggestions.length > 0 && (
				<div style={ { marginTop: '20px' } }>
					<h4
						style={ {
							marginBottom: '10px',
							fontSize: '14px',
						} }
					>
						{ __( 'Suggestions:', 'contextual-link-weaver' ) }
					</h4>
					{ suggestions.map( ( item, index ) => (
						<SuggestionItem
							key={ index }
							suggestion={ item }
							blocks={ blocks }
							updateBlockAttributes={ updateBlockAttributes }
							onInserted={ ( anchorText ) => {
								setSuggestions( ( prev ) =>
									prev.filter(
										( s ) =>
											s.anchor_text !== anchorText
									)
								);
							} }
						/>
					) ) }
				</div>
			) }
		</PanelBody>
	);
};

export default Sidebar;
