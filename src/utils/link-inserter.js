import { create, applyFormat, toHTMLString } from '@wordpress/rich-text';

/**
 * Normalize smart/curly quotes to straight ASCII equivalents.
 */
function normalizeQuotes( str ) {
	return str
		.replace( /[\u2018\u2019\u201A\u2032]/g, "'" )
		.replace( /[\u201C\u201D\u201E\u2033]/g, '"' );
}

/**
 * Strip all quote characters (straight and curly) from a string.
 */
function stripQuotes( str ) {
	return str.replace( /['""\u2018\u2019\u201A\u2032\u201C\u201D\u201E\u2033]/g, '' );
}

/**
 * Find anchor text in plain text, accounting for quotes the AI may have stripped.
 *
 * Returns { startIndex, endIndex } in the original plainText, or null.
 * Tries exact match first, then normalized quotes, then stripped quotes.
 */
function findAnchorInText( plainText, anchorText ) {
	// 1. Exact match.
	let idx = plainText.indexOf( anchorText );
	if ( idx !== -1 ) {
		return { startIndex: idx, endIndex: idx + anchorText.length };
	}

	// 2. Normalized quotes (smart → straight).
	const normalizedPlain = normalizeQuotes( plainText );
	const normalizedAnchor = normalizeQuotes( anchorText );
	idx = normalizedPlain.indexOf( normalizedAnchor );
	if ( idx !== -1 ) {
		return { startIndex: idx, endIndex: idx + normalizedAnchor.length };
	}

	// 3. Stripped quotes — the AI may omit quotes that exist in the content.
	// Build a mapping from stripped positions back to original positions.
	const strippedAnchor = stripQuotes( normalizedAnchor );
	if ( strippedAnchor.length === 0 ) {
		return null;
	}

	const origChars = []; // origChars[i] = index in normalizedPlain for the i-th non-quote char
	let strippedPlain = '';
	for ( let i = 0; i < normalizedPlain.length; i++ ) {
		const ch = normalizedPlain[ i ];
		if ( ! /['""]/.test( ch ) ) {
			origChars.push( i );
			strippedPlain += ch;
		}
	}

	const strippedIdx = strippedPlain.indexOf( strippedAnchor );
	if ( strippedIdx !== -1 ) {
		const startIndex = origChars[ strippedIdx ];
		const endStrippedIdx = strippedIdx + strippedAnchor.length - 1;
		// endIndex is one past the last matched character in the original string.
		// We need to include any trailing quote chars that are part of the span.
		let endIndex = origChars[ endStrippedIdx ] + 1;
		// Extend past any trailing quotes immediately after the match.
		while (
			endIndex < normalizedPlain.length &&
			/['""]/.test( normalizedPlain[ endIndex ] )
		) {
			endIndex++;
		}
		return { startIndex, endIndex };
	}

	return null;
}

/**
 * Resolve block content to an HTML string regardless of internal format.
 */
function getBlockHTML( block ) {
	const content = block.attributes?.content;
	if ( ! content ) {
		return null;
	}
	if ( typeof content === 'string' ) {
		return content;
	}
	// RichText value object — try originalHTML, then fall back to toHTMLString.
	if ( typeof content === 'object' ) {
		if ( content.originalHTML ) {
			return content.originalHTML;
		}
		// If it has text/formats, it's a RichTextValue — convert to HTML.
		if ( typeof content.text === 'string' ) {
			try {
				return toHTMLString( { value: content } );
			} catch ( e ) {
				return content.text;
			}
		}
	}
	return null;
}

/**
 * Insert a link into the first block that contains the anchor text.
 *
 * Uses @wordpress/rich-text applyFormat with core/link for proper Gutenberg integration.
 * Recursively searches inner blocks (columns, groups, etc.).
 *
 * @param {Array}    blocks                The editor blocks array.
 * @param {Function} updateBlockAttributes Dispatch function from core/block-editor.
 * @param {string}   anchorText            The text to wrap in a link.
 * @param {string}   url                   The URL to link to.
 * @return {boolean} Whether the link was inserted.
 */
export function insertLink( blocks, updateBlockAttributes, anchorText, url ) {
	for ( const block of blocks ) {
		const html = getBlockHTML( block );

		if ( html ) {
			// Create a RichTextValue from the block's HTML content.
			const richTextValue = create( { html } );
			const plainText = richTextValue.text;

			const match = findAnchorInText( plainText, anchorText );

			if ( match ) {
				const { startIndex, endIndex } = match;

				// Check if this range already has a link.
				const hasExistingLink = richTextValue.formats
					.slice( startIndex, endIndex )
					.some(
						( formatArray ) =>
							formatArray &&
							formatArray.some( ( f ) => f.type === 'core/link' )
					);

				if ( ! hasExistingLink ) {
					// Apply the link format.
					const newValue = applyFormat(
						richTextValue,
						{
							type: 'core/link',
							attributes: { url },
						},
						startIndex,
						endIndex
					);

					// Convert back to HTML and update the block.
					const newHTML = toHTMLString( { value: newValue } );
					updateBlockAttributes( block.clientId, {
						content: newHTML,
					} );

					// Scroll to the block and highlight it.
					scrollToAndHighlight( block.clientId );
					return true;
				}
			}
		}

		// Recursively search inner blocks.
		if ( block.innerBlocks?.length ) {
			const found = insertLink(
				block.innerBlocks,
				updateBlockAttributes,
				anchorText,
				url
			);
			if ( found ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Scroll to a block and briefly highlight it.
 */
function scrollToAndHighlight( clientId ) {
	requestAnimationFrame( () => {
		const blockEl = document.querySelector(
			`[data-block="${ clientId }"]`
		);
		if ( blockEl ) {
			blockEl.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			blockEl.style.transition = 'background-color 0.3s';
			blockEl.style.backgroundColor = '#ffffcc';
			setTimeout( () => {
				blockEl.style.backgroundColor = '';
			}, 2000 );
		}
	} );
}
