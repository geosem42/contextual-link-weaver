import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { insertLink } from '../utils/link-inserter';

const SuggestionItem = ( { suggestion, blocks, updateBlockAttributes, onInserted } ) => {
	const handleInsert = () => {
		const success = insertLink(
			blocks,
			updateBlockAttributes,
			suggestion.anchor_text,
			suggestion.url
		);

		if ( success ) {
			onInserted( suggestion.anchor_text );
		} else {
			// eslint-disable-next-line no-alert
			alert(
				`Could not find the exact phrase "${ suggestion.anchor_text }" in your content.`
			);
		}
	};

	const similarityPct = suggestion.similarity
		? Math.round( suggestion.similarity * 100 )
		: null;

	return (
		<div
			style={ {
				border: '1px solid #ddd',
				padding: '12px',
				borderRadius: '4px',
				marginBottom: '10px',
			} }
		>
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'flex-start',
					marginBottom: '8px',
				} }
			>
				<strong style={ { fontSize: '13px', flex: 1 } }>
					{ suggestion.title }
				</strong>
				{ similarityPct !== null && (
					<span
						style={ {
							fontSize: '11px',
							background: '#e7f5e7',
							color: '#2e7d32',
							padding: '2px 6px',
							borderRadius: '3px',
							marginLeft: '8px',
							whiteSpace: 'nowrap',
						} }
					>
						{ similarityPct }% match
					</span>
				) }
			</div>
			<p style={ { margin: '0 0 6px 0', fontSize: '13px' } }>
				<strong>
					{ __( 'Anchor text:', 'contextual-link-weaver' ) }
				</strong>{ ' ' }
				<em>"{ suggestion.anchor_text }"</em>
			</p>
			<p
				style={ {
					margin: '0 0 10px 0',
					fontSize: '12px',
					color: '#555',
				} }
			>
				{ suggestion.reasoning }
			</p>
			<Button variant="secondary" size="small" onClick={ handleInsert }>
				{ __( 'Insert Link', 'contextual-link-weaver' ) }
			</Button>
		</div>
	);
};

export default SuggestionItem;
