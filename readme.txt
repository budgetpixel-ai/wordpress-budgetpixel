=== BudgetPixel AI Images ===
Contributors: budgetpixel
Tags: ai, images, featured image, image generation, ai art
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate a featured image or inline image for any post from its title and excerpt, with 25+ image models and the credit cost shown before every run.

== Description ==

BudgetPixel AI Images adds AI image generation to the block editor. One BudgetPixel API key gives you 25+ image models from different labs, the exact credit cost is shown before every generation, and a WP-CLI command fills the missing featured images across your archive in one priced, confirmed run.

* **Featured images in one click.** A "BudgetPixel AI Image" panel in the post sidebar pre-fills a prompt from your title and excerpt. Edit it, pick a model, see the exact credit cost, generate, and set the result as the featured image or insert it into the post.
* **25+ image models.** FLUX 2, Seedream 5.0, Nano Banana, GPT Image, Qwen-Image, Kling and more, priced per image. The live model list and prices come from your account.
* **Cost before you spend.** Every generation shows its credit cost first. The plugin never spends credits without a click.
* **Bulk-fill missing featured images.** `wp budgetpixel featured --missing --dry-run` lists every post without a featured image and the total cost; drop `--dry-run` to generate them.
* **Sensible defaults.** Alt text defaults to the prompt. The model and prompt are stored as attachment meta so you can always see how an image was made.

**A paid BudgetPixel plan is required.** The plugin is free and open source; images are billed in credits from your BudgetPixel account at the same prices as budgetpixel.com. Create an API key at [budgetpixel.com/developers](https://budgetpixel.com/developers). See what each model costs per image on the [AI generation price index](https://budgetpixel.com/data/ai-generation-price-index).

Nothing is added to your public site unless you turn on the optional "Made with BudgetPixel" caption, which is off by default.

== External services ==

This plugin connects to the BudgetPixel API at `https://api.budgetpixel.com` to generate images. It is a paid third-party service operated by BudgetPixel.

* **What is sent:** your API key (in the request header), the prompt text, the chosen model and aspect ratio, and the plugin version with your site URL as the User-Agent. Nothing else about your site, posts or visitors is sent.
* **When:** only when you click Generate in the editor, load the model list or credit balance in the plugin's screens, use the Test connection button, or run the `wp budgetpixel` command. No background calls are made.
* **Where the results go:** generated images are downloaded into your Media Library. Generation history also appears in your BudgetPixel account.

Terms: [Developer API Terms](https://budgetpixel.com/developer-terms) · [Terms of Service](https://budgetpixel.com/terms) · [Privacy Policy](https://budgetpixel.com/privacy)

== Installation ==

1. Install and activate the plugin.
2. Create an API key at budgetpixel.com/developers (any paid plan).
3. Go to Settings → BudgetPixel, paste the key, pick a default model and aspect ratio, and use "Test connection".
4. Open a post; the "BudgetPixel AI Image" panel is in the settings sidebar.

To override the API origin (staging), add `define( 'BUDGETPIXEL_API_BASE', 'https://…/v1' );` to `wp-config.php`.

== Frequently Asked Questions ==

= Is the plugin free? =

Yes. The plugin is free and GPL-licensed. Generating an image uses credits from your BudgetPixel plan, at the same prices as budgetpixel.com. Any paid plan includes API access.

= How much does an image cost? =

It depends on the model, from about 10 to 120 credits per image, with 1,000 credits costing about one US dollar on a credit pack. The editor shows the exact cost before you generate, and the [price index](https://budgetpixel.com/data/ai-generation-price-index) lists every model.

= Where is my API key stored? =

In your WordPress options table, on your server. It is sent only to api.budgetpixel.com and never to the browser: the editor talks to the plugin's own REST endpoints, which call the API server-side.

= Who can generate images? =

Any logged-in user who can upload media (Authors and above by default). Attaching an image to a post also requires permission to edit that post.

= Does the plugin add anything to my public site? =

No. The only optional public mention is a "Made with BudgetPixel" image caption, which is off unless you enable it in settings.

= Can I use it from the command line? =

Yes: `wp budgetpixel featured --missing --dry-run` previews the posts and total cost; without `--dry-run` it generates and sets the featured images. Use `--limit`, `--model`, `--aspect` and `--post-type` to scope it.

== Screenshots ==

1. The BudgetPixel AI Image panel in the post sidebar, with the cost estimate.
2. Generated image with "Set as featured image" and "Insert into post".
3. Settings screen.

== Changelog ==

= 1.0.0 =
* Initial release: editor sidebar panel, featured-image and inline insertion, cost preview, WP-CLI bulk command, optional caption.
