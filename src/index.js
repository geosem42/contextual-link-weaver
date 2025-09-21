import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { PanelBody, Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const LinkWeaverIcon = () => <span className="dashicons dashicons-admin-links"></span>;

const LinkWeaverSidebar = () => {
    const [isLoading, setIsLoading] = useState(false);
    const [suggestions, setSuggestions] = useState([]);
    const [error, setError] = useState('');

    const { postContent, currentPostId, blocks } = useSelect((select) => ({
        postContent: select('core/editor').getEditedPostContent(),
        currentPostId: select('core/editor').getCurrentPostId(),
        blocks: select('core/block-editor').getBlocks(),
    }), []);

    const { replaceBlocks } = useDispatch('core/block-editor');

    const handleGenerateClick = () => {
        if (!postContent || postContent.trim().length === 0) {
            setError(__('Cannot generate suggestions for an empty post.', 'contextual-link-weaver'));
            return;
        }
        setIsLoading(true);
        setError('');
        setSuggestions([]);

        apiFetch({
            path: '/contextual-link-weaver/v1/suggestions',
            method: 'POST',
            data: { 
                content: postContent,
                post_id: currentPostId
            },
        })
        .then((response) => {
            if (Array.isArray(response)) {
                setSuggestions(response);
            } else {
                setError(__('The API returned an unexpected format.', 'contextual-link-weaver'));
            }
            setIsLoading(false);
        })
        .catch((err) => {
            setError(err.message || __('An unknown error occurred.', 'contextual-link-weaver'));
            setIsLoading(false);
        });
    };

    const handleInsertLink = (anchorText, url) => {
        let linkInserted = false;
        let targetBlockClientId = null;

        const newBlocks = blocks.map((block) => {
            if (linkInserted || !block.attributes.content) {
                return block;
            }
            
            let originalContent = '';
            if (typeof block.attributes.content === 'string') {
                originalContent = block.attributes.content;
            } else if (typeof block.attributes.content === 'object' && block.attributes.content.originalHTML) {
                originalContent = block.attributes.content.originalHTML;
            } else {
                return block;
            }

            const linkedText = `<a href="${url}" class="clw-inserted-link">${anchorText}</a>`;

            if (originalContent.includes(anchorText) && !originalContent.includes(linkedText)) {
                const newContent = originalContent.replace(anchorText, linkedText);
                const newAttributes = { ...block.attributes, content: newContent };
                targetBlockClientId = block.clientId; 
                linkInserted = true;
                return { ...block, attributes: newAttributes };
            }

            return block;
        });

        if (linkInserted && targetBlockClientId) {
            const editorWrapper = document.querySelector('.editor-styles-wrapper');

            if (editorWrapper) {
                const observer = new MutationObserver((mutationsList, obs) => {
                    const targetBlock = document.querySelector(`[data-block="${targetBlockClientId}"]`);
                    if (targetBlock) {
                        // The block is now in the DOM, so we can act on it.
                        targetBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        
                        const link = targetBlock.querySelector('a.clw-inserted-link');
                        if (link) {
                            link.classList.add('clw-highlight-link');
                            link.classList.remove('clw-inserted-link');
                        }
                        
                        obs.disconnect(); // We're done, so we disconnect the observer.
                    }
                });

                // Start observing the editor for any changes to its child nodes.
                observer.observe(editorWrapper, { childList: true, subtree: true });
            }

            // Now that our observer is watching, trigger the change.
            replaceBlocks(blocks.map(b => b.clientId), newBlocks);
            setSuggestions(suggestions.filter(s => s.anchor_text !== anchorText));
        } else {
            alert(`Could not find the exact phrase "${anchorText}" in your content. It might be split across multiple paragraphs.`);
        }
    };

    return (
        <>
            <PluginSidebarMoreMenuItem target="link-weaver-sidebar" icon={<LinkWeaverIcon />}>
                {__('Link Weaver', 'contextual-link-weaver')}
            </PluginSidebarMoreMenuItem>
            
            <PluginSidebar name="link-weaver-sidebar" title={__('Link Weaver', 'contextual-link-weaver')}>
                <PanelBody title={__('Link Suggestions', 'contextual-link-weaver')}>
                    <p>
                        {__('Click the button below to scan this post and generate internal link suggestions.', 'contextual-link-weaver')}
                    </p>
                    <Button isPrimary onClick={handleGenerateClick} isBusy={isLoading}>
                        {isLoading ? __('Generating...', 'contextual-link-weaver') : __('Generate Suggestions', 'contextual-link-weaver')}
                    </Button>

                    {isLoading && <Spinner style={{ marginTop: '10px' }} />}
                    {error && <p style={{ color: 'red', marginTop: '10px' }}>{error}</p>}
                    
                    {suggestions && suggestions.length > 0 && (
                        <div style={{ marginTop: '20px' }}>
                            <h4 style={{ marginBottom: '10px', fontSize: '14px' }}>{__('Suggestions:', 'contextual-link-weaver')}</h4>
                            <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                                {suggestions.map((item, index) => (
                                    <li key={index} style={{ border: '1px solid #ddd', padding: '12px', borderRadius: '4px', marginBottom: '10px' }}>
                                        <p style={{ margin: '0 0 8px 0', fontSize: '13px' }}>
                                            <strong>{__('Phrase:', 'contextual-link-weaver')}</strong><br />
                                            <span style={{ fontStyle: 'italic' }}>"{item.anchor_text}"</span>
                                        </p>
                                        <p style={{ margin: '0 0 12px 0', fontSize: '12px', color: '#555' }}>
                                            <strong>{__('Link to Post:', 'contextual-link-weaver')}</strong><br />
                                            {item.title}
                                        </p>
                                        <Button isSecondary isSmall onClick={() => handleInsertLink(item.anchor_text, item.url)}>
                                            {__('Insert Link', 'contextual-link-weaver')}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </PanelBody>
            </PluginSidebar>
        </>
    );
};

registerPlugin('link-weaver-plugin', {
    render: LinkWeaverSidebar,
});