# Contextual Link Weaver for WordPress

**Contextual Link Weaver** is an intelligent internal linking assistant that uses the Gemini AI (`gemini-2.5-flash`) to provide context-aware link suggestions directly in the WordPress editor. It's designed to streamline your SEO workflow and help you build a powerful internal link graph with minimal effort.

![Plugin Screenshot](https://i.imgur.com/RqZWouw.png)

## Features

- **AI-Powered Suggestions:** Leverages the Gemini AI (`gemini-2.5-flash`) to understand the context of your content and provide highly relevant link suggestions.
- **Gutenberg Integration:** A clean, intuitive sidebar panel right where you need it in the post editor.
- **One-Click Insertion:** Insert links with a single click, and the plugin scrolls you to the newly created link.
- **Prompt Engineering:** Fine-tune the AI's behavior with a detailed prompt to match your site's specific SEO strategy.

## Installation

1.  Download the latest release from the [Releases](https://github.com/geosem42/contextual-link-weaver) page.
2.  In your WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3.  Upload the `.zip` file and activate the plugin.
4.  Navigate to **Settings > Link Weaver** and enter your Google Gemini API key.
5.  Open any post or page to start using the Link Weaver sidebar.

## Development

This plugin uses modern JavaScript tooling. To work on the source files:

1.  Clone the repository.
2.  Navigate to the plugin directory: `cd contextual-link-weaver`
3.  Install dependencies: `npm install`
4.  Run the build process for development: `npm start`