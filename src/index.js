import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import Sidebar from './components/Sidebar';

registerPlugin( 'link-weaver-plugin', {
	render: () => (
		<>
			<PluginSidebarMoreMenuItem
				target="link-weaver-sidebar"
				icon="admin-links"
			>
				{ __( 'Link Weaver', 'contextual-link-weaver' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="link-weaver-sidebar"
				title={ __( 'Link Weaver', 'contextual-link-weaver' ) }
				icon="admin-links"
			>
				<Sidebar />
			</PluginSidebar>
		</>
	),
} );
