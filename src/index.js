import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import Sidebar from './components/Sidebar';

registerPlugin( 'interpost-plugin', {
	render: () => (
		<>
			<PluginSidebarMoreMenuItem
				target="interpost-sidebar"
				icon="admin-links"
			>
				{ __( 'Interpost', 'interpost' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="interpost-sidebar"
				title={ __( 'Interpost', 'interpost' ) }
				icon="admin-links"
			>
				<Sidebar />
			</PluginSidebar>
		</>
	),
} );
