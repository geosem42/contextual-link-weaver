import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import Sidebar from './components/Sidebar';

registerPlugin( 'pagelace-plugin', {
	render: () => (
		<>
			<PluginSidebarMoreMenuItem
				target="pagelace-sidebar"
				icon="admin-links"
			>
				{ __( 'Pagelace', 'pagelace-internal-links' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="pagelace-sidebar"
				title={ __( 'Pagelace', 'pagelace-internal-links' ) }
				icon="admin-links"
			>
				<Sidebar />
			</PluginSidebar>
		</>
	),
} );
