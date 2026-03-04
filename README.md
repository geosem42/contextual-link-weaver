# Contextual Link Weaver

A WordPress plugin that uses Gemini AI and semantic embeddings to provide intelligent, context-aware internal linking suggestions in the Gutenberg editor.

## How It Works

1. **Index your posts** — The plugin generates a semantic embedding (via Gemini's `gemini-embedding-001` model) for each published post and stores it in a custom database table. This happens automatically when posts are published/updated, or you can bulk-index from the settings page.

2. **Find related posts** — When editing a post, click "Find Related Posts" in the Link Weaver sidebar. The plugin embeds your draft content and uses cosine similarity to find the most semantically related posts — no AI call needed for this step.

3. **Get anchor text suggestions** — Only the top 15 most similar posts are sent to Gemini (`gemini-3-flash-preview`) along with your draft. The AI selects the best anchor text phrases and matches them to related posts.

4. **Insert links** — Click "Insert Link" to add the link directly into your editor at the suggested anchor text location.

## Setup

1. Install and activate the plugin.
2. Go to **Settings > Link Weaver** and enter your [Google Gemini API key](https://aistudio.google.com/apikey).
3. Click **Index All Posts** to generate embeddings for your existing published posts.
4. Open any post in the Gutenberg editor and use the Link Weaver sidebar.

## Requirements

- WordPress 6.8+ (Gutenberg block editor)
- PHP 8.2+
- A Google Gemini API key

## Development

```bash
npm install
npm start    # Development mode with watch
npm run build  # Production build
```

## License

GPL v2 or later.
