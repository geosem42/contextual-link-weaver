=== Interpost AI Internal Links ===
Contributors: geosem
Tags: internal links, seo, ai, related posts, block editor
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered internal linking: semantic embeddings find your most related posts and suggest natural anchor text, right in the block editor.

== Description ==

Interpost helps you build a strong internal link structure without leaving the editor. Instead of keyword matching, it understands what your posts are *about*. Semantic embeddings find genuinely related content, then AI picks anchor text that already exists naturally in your draft.

Interpost is made by Logic Void. The plugin page is at [logicvoid.dev/plugins/interpost](https://logicvoid.dev/plugins/interpost?ref=wporg).

= How it works =

1. **Index your posts.** The plugin generates a semantic embedding for each published post and stores it locally in your database. This happens automatically on publish/update, or in bulk from the settings page.
2. **Find related posts.** While editing, click *Find Related Posts* in the Interpost sidebar. Your draft is embedded and compared against your indexed posts by cosine similarity. No AI call is needed for this step beyond the single draft embedding.
3. **Get anchor text suggestions.** Only your top 15 most similar posts are sent to the AI along with your draft. It selects natural 4 to 6 word phrases that exist verbatim in your text and pairs each with the most relevant post.
4. **Insert links.** One click adds the link at the suggested anchor text location in the editor.

= Why semantic instead of keyword matching? =

Keyword-based internal linking tools suggest links wherever a word happens to appear. Interpost compares the *meaning* of your draft against the meaning of every published post, so a post about "neuroplasticity in adults" can be linked from a draft about "learning new skills after 40" even if they share no keywords.

= Interpost Pro =

The free plugin works on the post you are editing. A Pro version is in development that covers a whole site at once:

* Scan every published post in one run, instead of one draft at a time
* Custom post types and taxonomies
* An orphan report, showing what has nothing linking to it
* Include and exclude rules by category, tag and post type
* A link inventory, and undo in bulk
* Scheduled re-scans as your content changes
* A choice of Gemini model
* Support by email, and a year of updates

Pro is a second plugin that installs alongside this one, which stays free and stays required. It is not released yet. What is planned is listed at [logicvoid.dev/plugins/interpost](https://logicvoid.dev/plugins/interpost?ref=wporg).

= Requirements =

* WordPress 6.8+ with the block editor (Gutenberg)
* PHP 8.2+
* A free Google Gemini API key ([get one here](https://aistudio.google.com/apikey))

== External services ==

This plugin connects to the **Google Gemini API** (`generativelanguage.googleapis.com`) to provide its core functionality. You must supply your own API key.

**What is sent and when:**

* When a post is indexed (on publish/update, or during bulk indexing), the post's title and content (first 2,000 words, HTML stripped) are sent to Google's embedding API to generate a semantic embedding.
* When you click *Find Related Posts* in the editor, your draft content is sent to Google's embedding API, and then your draft plus the titles, URLs, and excerpts of your top 15 most similar published posts are sent to Google's generative API to produce anchor text suggestions.

No data is sent anywhere unless you have entered an API key. No data is sent to any service other than Google. The embeddings themselves are stored locally in your own WordPress database.

This service is provided by Google: [Terms of Service](https://ai.google.dev/gemini-api/terms), [Privacy Policy](https://policies.google.com/privacy).

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → Interpost** and enter your [Google Gemini API key](https://aistudio.google.com/apikey).
3. Click **Index All Posts** to generate embeddings for your existing published posts.
4. Open any post in the block editor and use the **Interpost** sidebar.

== Frequently Asked Questions ==

= Do I need to pay for the AI? =

You need a Google Gemini API key, which has a free tier that is sufficient for most blogs. Very large sites or heavy re-indexing may exceed free-tier quotas; costs are between you and Google.

= Is my content sent to external servers? =

Yes. Post content is sent to Google's Gemini API for embedding and suggestion generation, as described in the *External services* section. Nothing is sent until you configure an API key, and nothing is sent to any other service.

= Which post types are supported? =

Published posts (`post` type) are indexed and suggested. Pages and custom post types are not currently supported.

= Does it work with the Classic Editor? =

No. The suggestions sidebar is built for the block editor (Gutenberg).

= Where are embeddings stored? =

In a dedicated table in your own WordPress database.

= What happens to my index if I remove the plugin? =

Deactivating changes nothing. Deleting the plugin keeps the index too, because rebuilding it means one Gemini API call for every post. If you want it gone, tick *Also remove the embedding index and the API key* on the settings page before you delete.

= Is there a paid version? =

Not yet. A Pro version is in development for site-wide scanning, orphan reports and scheduled re-scans. This free plugin stays free, and Pro requires it. See [logicvoid.dev/plugins/interpost](https://logicvoid.dev/plugins/interpost?ref=wporg).

== Screenshots ==

1. The Interpost sidebar in the block editor showing anchor text suggestions.
2. Settings page with API key field and bulk indexing progress.

== Changelog ==

= 2.3.0 =
* Added: a "Site-wide linking" tab on the settings screen, describing what the paid add-on covers and which parts of it are still being built. The tab is not shown when the add-on is installed.

= 2.2.1 =
* Fixed: the editor sidebar appeared on content types that are not indexed, where it had no suggestions to offer. It now loads for indexed content types, and the suggestions endpoint declines requests for the rest.

= 2.2.0 =
* Deleting the plugin now keeps your embedding index and API key. Rebuilding the index costs one Gemini API call for every post, so removing it is opt in: tick the box on the settings page first.

= 2.1.1 =
* Fixed: the bulk indexing progress text showed a doubled percent sign.

= 2.1.0 =
* Fixed: bulk indexing could repeat the same batch forever when a post could not be indexed. It now skips posts that have already failed, stops when a batch gets nowhere, and reports how many failed.
* Fixed: deleting a post for good left its embedding behind, so it kept competing for a place in the suggestions.
* Fixed: the index status counted every stored embedding against published posts alone, which could report more indexed than there were posts.
* Fixed: posts embedded at a different vector width are now skipped instead of scored, which silently produced meaningless similarity numbers.
* Added: filters for the indexed post types and statuses, the embedding model and its dimensions, and the shortlist of suggested posts.
* The API error for a failed embedding request now carries the HTTP status as data, so it can be read without parsing the message.

= 2.0.0 =
* Semantic embedding index with automatic indexing on publish/update and bulk indexing with progress.
* Editor sidebar with AI anchor text suggestions and one-click link insertion.
* API key now sent via request header instead of URL.
* Full internationalization support.
* Clean uninstall: removes table and options on plugin deletion.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.3.0 =
Adds a settings tab describing the paid add-on. Nothing else changes.

= 2.2.1 =
The editor sidebar now appears on the content types that are indexed, instead of every content type.

= 2.2.0 =
Deleting the plugin no longer removes your embedding index or API key unless you ask it to on the settings page.

= 2.1.1 =
Corrects a doubled percent sign in the indexing progress text.

= 2.1.0 =
Fixes a bulk indexing loop that could keep calling the API without making progress. Recommended for everyone.

= 2.0.0 =
First public release on WordPress.org.
