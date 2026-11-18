# BudgetPixel AI Images — WordPress plugin

Generate a featured image or inline image for any post from its title and excerpt, with 25+ image models and the credit cost shown before every run. Bulk-fill missing featured images from WP-CLI.

- Plugin page: https://budgetpixel.com/plugins/wordpress
- API docs: https://docs.budgetpixel.com
- A paid BudgetPixel plan is required for API access; the plugin itself is free (GPL-2.0-or-later).

## Install from source

Download `budgetpixel-ai-images.zip` from the [latest release](https://github.com/budgetpixel-ai/wordpress-budgetpixel/releases/latest) and upload it under Plugins → Add New → Upload Plugin, or clone this repository into `wp-content/plugins/budgetpixel-ai-images`.

WordPress.org listing: https://wordpress.org/plugins/budgetpixel-ai-images/ — approved 2026-09-12. The easiest install is Plugins → Add New → search "BudgetPixel AI Images"; releases here stay in step with the directory (keep the directory version >= the GitHub release so GitHub installs auto-update).

## Development

No build step: `assets/js/editor.js` runs on the `wp.*` globals. Lint with `php -l` on every file; `readme.txt` follows the WordPress.org format. Tag `vX.Y.Z` to deploy to WordPress.org via `.github/workflows/deploy.yml` (no-op until the `WPORG_DEPLOY_ENABLED` repository variable is set after approval).

## Support

support@budgetpixel.com

## More from BudgetPixel

The same BudgetPixel account also runs these studios in the browser:

- [Director](https://budgetpixel.com/director): an AI movie maker that turns an idea into a short film, step by step.
- [Music Video Studio](https://budgetpixel.com/music-video): an AI music video maker. Bring your song; the singers lip-sync to it.
- [Recast](https://budgetpixel.com/recast): recast a short video with a new cast, keeping the moves, timing and sound.
- [Clip Studio](https://studio.budgetpixel.com): an [AI shorts generator](https://studio.budgetpixel.com/shorts-studio), an [AI B-roll generator](https://studio.budgetpixel.com/broll-studio) and a [faceless video generator](https://studio.budgetpixel.com/explainer-studio) for your own footage.
- [Design Studio](https://budgetpixel.com/design): an AI designer with free, editable templates.
